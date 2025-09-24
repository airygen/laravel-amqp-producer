<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ;

use Airygen\RabbitMQ\Contracts\ProducerPayloadInterface;

abstract class ProducerPayload implements ProducerPayloadInterface
{
    protected string $connectionName = 'default';

    protected ?string $exchangeName = null;

    protected ?string $routingKey = null;

    public function __construct(protected array $data) {}

    public function getConnectionName(): ?string
    {
        return $this->connectionName;
    }

    public function getExchangeName(): ?string
    {
        return $this->exchangeName;
    }

    public function getRoutingKey(): ?string
    {
        return $this->routingKey;
    }

    public function jsonSerialize(): mixed
    {
        return $this->data;
    }
}
