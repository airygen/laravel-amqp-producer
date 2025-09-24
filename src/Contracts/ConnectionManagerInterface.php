<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Contracts;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;

interface ConnectionManagerInterface
{
    public function get(string $name = 'default'): AbstractConnection;

    /**
     * @template T
     * @param callable(AMQPChannel):T $fn
     * @return T
     */
    public function withChannel(callable $fn, string $connectionName = 'default');

    public function reset(?string $name = null): void;
}
