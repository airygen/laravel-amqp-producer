<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Connection;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;

interface ConnectionManagerInterface
{
    public function get(): AbstractConnection;

    /**
     * @template T
     *
     * @param  callable(AMQPChannel):T  $fn
     * @return T
     */
    public function withChannel(callable $fn);

    public function reset(): void;
}
