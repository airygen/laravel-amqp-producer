<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Contracts;

use JsonSerializable;

interface ProducerPayload extends JsonSerializable
{
    /**
     * The connection defined at config/queue.php
     *
     * @return string|null
     */
    public function getConnectionName(): ?string;

    /**
     * Push message to the specfic routing if present.
     *
     * @return string|null
     */
    public function getRoutingKey(): ?string;

    /**
     * The payload.
     *
     * @return mixed
     */
    public function jsonSerialize(): mixed;
}
