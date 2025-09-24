<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Contracts;

use JsonSerializable;

interface ProducerPayloadInterface extends JsonSerializable
{
    /**
     * The channel name defined in config/amqp.php.
     * Return null to use the default channel.
     */
    public function getConnectionName(): ?string;

    /**
     * Push message to the specific exchange if present.
     * Required for direct or topic exchange type.
     */
    public function getExchangeName(): ?string;

    /**
     * Push message to the specific routing if present.
     * Required for direct or topic exchange type.
     */
    public function getRoutingKey(): ?string;

    /**
     * The payload.
     */
    public function jsonSerialize(): mixed;
}
