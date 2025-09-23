<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Connection;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;

final class ConnectionManager implements ConnectionManagerInterface
{
    private ?AbstractConnection $conn = null;

    public function __construct(private ConnectionFactory $factory) {}

    public function get(): AbstractConnection
    {
        if ($this->conn === null || ! $this->conn->isConnected()) {
            $this->conn = $this->factory->create();
        }

        return $this->conn;
    }

    /**
     * @template T
     *
     * @param  callable(AMQPChannel):T  $fn
     * @return T
     */
    public function withChannel(callable $fn)
    {
        $conn = $this->get();
        $channel = $conn->channel();
        try {
            return $fn($channel);
        } finally {
            try {
                if ($channel->is_open()) {
                    $channel->close();
                }
            } catch (\Throwable) {
            }
        }
    }

    public function reset(): void
    {
        if ($this->conn) {
            try {
                $this->conn->close();
            } catch (\Throwable) {
            }
        }
        $this->conn = null;
    }
}
