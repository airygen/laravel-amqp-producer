<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Producer;

use Airygen\RabbitMQ\Config\ChannelResolver;
use Airygen\RabbitMQ\Connection\ConnectionManagerInterface;
use Airygen\RabbitMQ\Contracts\ProducerInterface;
use Airygen\RabbitMQ\Contracts\ProducerPayload;
use Airygen\RabbitMQ\Messaging\MessageFactory;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Exception\AMQPProtocolConnectionException;
use RuntimeException;
use Throwable;

final class Publisher implements ProducerInterface
{
    public function __construct(
        private ConnectionManagerInterface $cm,
        private MessageFactory $msgFactory,
        private ChannelResolver $resolver,
    ) {}

    public function publish(ProducerPayload $payload, ?array $header = null, ?int $retryTimes = 3, ?callable $when = null): void
    {
        $config = $this->resolver->resolve($payload);
        $rk = $payload->getRoutingKey() ?? ($config['routing_key'] ?? '');
        $ex = $config['exchange_name'] ?? '';

        $this->retry($retryTimes ?? 3, $when ?? $this->transientWhen(), function () use ($payload, $header, $rk, $ex) {
            $this->cm->withChannel(function (AMQPChannel $ch) use ($payload, $header, $rk, $ex) {
                $ch->confirm_select();
                $msg = $this->msgFactory->make($payload, $header);

                $ch->basic_publish($msg, $ex, $rk, true);
                $ok = $ch->wait_for_pending_acks_returns(5.0);
                if ($ok === false) {
                    throw new RuntimeException('Publisher confirm timeout or nack');
                }
            });
        });
    }

    public function batchPublish(array $payloads, ?array $header = null, ?int $retryTimes = 3, ?callable $when = null): void
    {
        if ($payloads === []) {
            return;
        }

        $this->retry($retryTimes ?? 3, $when ?? $this->transientWhen(), function () use ($payloads, $header) {
            $this->cm->withChannel(function (AMQPChannel $ch) use ($payloads, $header) {
                $ch->confirm_select();

                foreach ($payloads as $payload) {
                    $config = $this->resolver->resolve($payload);
                    $rk = $payload->getRoutingKey() ?? ($config['routing_key'] ?? '');
                    $ex = $config['exchange_name'] ?? '';
                    $msg = $this->msgFactory->make($payload, $header);
                    $ch->basic_publish($msg, $ex, $rk, true);
                }

                $ok = $ch->wait_for_pending_acks_returns(5.0);
                if ($ok === false) {
                    throw new RuntimeException('Publisher confirm timeout or nack (batch)');
                }
            });
        });
    }

    private function retry(int $times, callable $when, callable $cb): void
    {
        $delay = 0.2;
        $last = null;
        for ($i = 0; $i < max(1, $times); $i++) {
            try {
                $cb();

                return;
            } catch (Throwable $e) {
                $last = $e;
                if (! $when($e)) {
                    throw $e;
                }
                usleep((int) ($delay * 1_000_000));
                $delay = min($delay * 2, 1.5);
                $this->cm->reset();
            }
        }
        throw $last ?? new RuntimeException('Unknown publish failure');
    }

    private function transientWhen(): callable
    {
        return static fn (Throwable $e) => $e instanceof AMQPIOException
            || $e instanceof AMQPProtocolConnectionException
            || $e instanceof AMQPProtocolChannelException;
    }
}
