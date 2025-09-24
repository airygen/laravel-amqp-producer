<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Contracts;

interface ProducerInterface
{
    /**
     * @param array<string,mixed>|null $header Custom headers merged over automatic ones.
     * @param int|null $retryTimes Override retry attempts (default internal constant if null).
     * @param callable(\Throwable):bool|null $when Decider: return true to retry, false to fail fast.
     */
    public function publish(
        ProducerPayloadInterface $payload,
        ?array $header = null,
        ?int $retryTimes = null,
        ?callable $when = null
    ): void;

    /**
     * @param list<ProducerPayloadInterface> $payloads
     * @param array<string,mixed>|null $header Custom headers.
     * @param int|null $retryTimes Retry attempts per connection group.
     * @param callable(\Throwable):bool|null $when Retry decider.
     */
    public function batchPublish(
        array $payloads,
        ?array $header = null,
        ?int $retryTimes = null,
        ?callable $when = null
    ): void;
}
