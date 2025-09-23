<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Contracts;

interface ProducerInterface
{
    /**
     * @param ProducerPayload[] $payloads
     * @param array|null $headers
     * @param int|null $retryTimes
     * @param callable|null $when — Retry decider for publish/batchPublish.
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
     *
     * @return void
     */
    public function publish(
        ProducerPayload $payload,
        ?array $header = null,
        ?int $retryTimes = null,
        ?callable $when = null
    ): void;

    public function batchPublish(
        array $payloads,
        ?array $header = null,
        ?int $retryTimes = null,
        ?callable $when = null
    ): void;
}
