<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ;

use Airygen\RabbitMQ\Contracts\ConnectionManagerInterface;
use Airygen\RabbitMQ\Contracts\ExceptionClassifierInterface;
use Airygen\RabbitMQ\Contracts\ProducerInterface;
use Airygen\RabbitMQ\Contracts\ProducerPayloadInterface;
use Airygen\RabbitMQ\Factories\MessageFactory;
use Airygen\RabbitMQ\Support\DefaultExceptionClassifier;
use Airygen\RabbitMQ\Support\Stats;
use PhpAmqpLib\Channel\AMQPChannel;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class Publisher implements ProducerInterface
{
    public const DEFAULT_RETRY_TIMES = 3;

    public const DEFAULT_CONNECTION_NAME = 'default';

    private ExceptionClassifierInterface $classifier;

    private ?LoggerInterface $logger;

    private float $retryBaseDelay;

    private float $retryMaxDelay;

    private bool $retryJitter;

    /** @var callable */
    private $randFloat;

    public function __construct(
        private ConnectionManagerInterface $cm,
        private MessageFactory $msgFactory,
        ?ExceptionClassifierInterface $classifier = null,
        ?LoggerInterface $logger = null,
        array $retryConfig = [],
        ?callable $randFloat = null
    ) {
        $this->classifier = $classifier ?? new DefaultExceptionClassifier();
        $this->logger = $logger;
        $this->retryBaseDelay = (float) ($retryConfig['base_delay'] ?? 0.2);
        $this->retryMaxDelay = (float) ($retryConfig['max_delay'] ?? 1.5);
        $this->retryJitter = (bool) ($retryConfig['jitter'] ?? false);
        $this->randFloat = $randFloat ?? static fn (): float => mt_rand() / mt_getrandmax();
    }

    /**
     * @param  ProducerPayload[]  $payloads
     * @param  array|null  $headers
     * @param  int|null  $retryTimes  — Max retry attempts
     * @param  callable|null  $when  — Retry decider
     *
     * Purpose:
     *   A callable invoked whenever publishing throws an exception. It decides
     *   whether the library should retry the operation.
     *
     * Signature:
     *   callable(\Throwable $e): bool
     *
     * Return value:
     *   - true  => treat the exception as transient and retry
     *   - false => do NOT retry; rethrow the exception immediately
     *
     * Defaults:
     *   If null, a built-in decider is used that retries only on transient AMQP
     *   connection/channel errors (e.g. AMQPIOException, AMQPProtocol* exceptions).
     *
     * Retry policy:
     *   Exponential backoff starting at ~200ms and doubling up to ~1.5s
     *   (bounded by $retryTimes attempts).
     *
     * Examples:
     *
     *   1) Default behavior (omit $when): retry only on transient AMQP errors
     *
     *   $producer->publish($payload);
     *
     *   2) Only retry on IO-related AMQP exceptions
     *
     *   $producer->publish(
     *       $payload,
     *       when: fn(\Throwable $e) => $e instanceof \PhpAmqpLib\Exception\AMQPIOException,
     *       retryTimes: 3
     *   );
     *
     *   3) Never retry (fail fast)
     *
     *   $producer->publish(
     *       $payload,
     *       when: fn() => false,
     *       retryTimes: 1
     *   );
     *
     *   4) Custom rule: retry on timeouts, but not on NACKs
     *
     *   $when = function (\Throwable $e): bool {
     *       if ($e instanceof \RuntimeException && str_contains($e->getMessage(), 'timeout')) {
     *           return true;  // likely transient congestion
     *       }
     *       if ($e instanceof \RuntimeException && str_contains($e->getMessage(), 'nack')) {
     *           return false;  // likely configuration/unroutable issue
     *       }
     *       return false;
     *   };
     *   $producer->publish($payload, when: $when, retryTimes: 4);
     */
    public function publish(
        ProducerPayloadInterface $payload,
        ?array $header = null,
        ?int $retryTimes = 3,
        ?callable $when = null
    ): void {
        $routingKey = $payload->getRoutingKey() ?? '';
        $exchange = $payload->getExchangeName() ?? '';
        $connectionName = $payload->getConnectionName() ?? self::DEFAULT_CONNECTION_NAME;

        $this->retry(
            $retryTimes ?? self::DEFAULT_RETRY_TIMES,
            $when ?? [$this->classifier, 'isTransient'],
            function () use ($payload, $header, $routingKey, $exchange, $connectionName) {
                $this->cm->withChannel(
                    function (AMQPChannel $channel) use ($payload, $header, $routingKey, $exchange) {
                        $channel->confirm_select();
                        $msg = $this->msgFactory->make($payload, $header);
                        $channel->basic_publish($msg, $exchange, $routingKey, true);
                        $ok = $channel->wait_for_pending_acks_returns(5.0);
                        if ($ok === false) {
                            throw new RuntimeException('Publisher confirm timeout or nack');
                        }
                    },
                    $connectionName
                );
            },
            $connectionName
        );
    }

    public function batchPublish(
        array $payloads,
        ?array $header = null,
        ?int $retryTimes = 3,
        ?callable $when = null
    ): void {
        if ($payloads === []) {
            return;
        }
        // 將 payload 依連線分組，對每個連線做獨立 retry，避免某一連線 transient 錯誤重置所有連線。
        $groups = [];
        foreach ($payloads as $payload) {
            $name = $payload->getConnectionName() ?? self::DEFAULT_CONNECTION_NAME;
            $groups[$name][] = $payload;
        }

        $attempts = $retryTimes ?? self::DEFAULT_RETRY_TIMES;
        $decider = $when ?? [$this->classifier, 'isTransient'];

        foreach ($groups as $connectionName => $groupPayloads) {
            $this->retry(
                $attempts,
                $decider,
                function () use ($groupPayloads, $header, $connectionName) {
                    // 單一連線開一個 channel (可能被重用) 內連續發多則訊息
                    $this->cm->withChannel(
                        function (AMQPChannel $ch) use ($groupPayloads, $header) {
                            $ch->confirm_select();
                            foreach ($groupPayloads as $payload) {
                                $routingKey = $payload->getRoutingKey() ?? '';
                                $exchange = $payload->getExchangeName() ?? '';
                                $msg = $this->msgFactory->make($payload, $header);
                                $ch->basic_publish($msg, $exchange, $routingKey, true);
                                $ok = $ch->wait_for_pending_acks_returns(5.0);
                                if ($ok === false) {
                                    throw new RuntimeException('Publisher confirm timeout or nack (batch)');
                                }
                            }
                        },
                        $connectionName
                    );
                },
                $connectionName
            );
        }
    }

    private function retry(int $times, callable $when, callable $cb, ?string $connectionName = null): void
    {
        $delay = $this->retryBaseDelay;
        $last = null;
        for ($i = 0; $i < max(1, $times); $i++) {
            try {
                Stats::incr('publish_attempts');
                if ($connectionName) {
                    Stats::incrConnection($connectionName, 'publish_attempts');
                }
                $cb();
                if ($i > 0) {
                    $this->log('info', 'publish succeeded after retries', ['attempts' => $i + 1]);
                }

                return;
            } catch (Throwable $e) {
                $last = $e;
                if (! $when($e)) {
                    Stats::incr('publish_failures');
                    if ($connectionName) {
                        Stats::incrConnection($connectionName, 'publish_failures');
                    }
                    $this->log('error', 'non-transient publish failure', [
                        'attempt' => $i + 1,
                        'exception' => get_class($e),
                        'message' => $e->getMessage(),
                    ]);
                    throw $e;
                }
                Stats::incr('publish_retries');
                if ($connectionName) {
                    Stats::incrConnection($connectionName, 'publish_retries');
                }
                $sleep = $delay;
                if ($this->retryJitter) {
                    $factor = 0.85 + ($this->randFloat)() * 0.30; // 0.85 - 1.15 range
                    $sleep = $delay * $factor;
                }
                $this->log('warning', 'transient publish failure, retrying', [
                    'attempt' => $i + 1,
                    'delay' => $sleep,
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                ]);
                usleep((int) ($sleep * 1_000_000));
                $delay = min($delay * 2, $this->retryMaxDelay);
                if ($connectionName !== null) {
                    $this->cm->reset($connectionName);
                    Stats::incr('connection_resets');
                    Stats::incrConnection($connectionName, 'connection_resets');
                }
            }
        }
        throw $last ?? new RuntimeException('Unknown publish failure');
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger) {
            try {
                $this->logger->log($level, $message, $context);
            } catch (\Throwable) {
            }
        }
    }
}
