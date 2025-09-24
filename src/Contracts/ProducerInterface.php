<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Contracts;

interface ProducerInterface
{
    public function publish(
        ProducerPayloadInterface $payload,
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
