<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Consumer;

use PhpAmqpLib\Message\AMQPMessage;

interface ConsumerInterface
{
    public function handle(AMQPMessage $message): ProcessingResult;
}
