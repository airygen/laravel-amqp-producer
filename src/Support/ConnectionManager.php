<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Support;

use Airygen\RabbitMQ\Contracts\ConnectionManagerInterface;
use Airygen\RabbitMQ\Factories\ConnectionFactory;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use Throwable;

final class ConnectionManager implements ConnectionManagerInterface
{
    /** @var array<string, AbstractConnection|null> */
    private array $connections = [];

    /**
     * Extracted connections definitions
     * @var array<string,array<string,mixed>>
     */
    private array $connectionDefinitions;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(
        private ConnectionFactory $factory,
        array $config
    ) {
        $this->connectionDefinitions = $config['connections'] ?? $config; // backward compatibility
    }

    public function get(string $name = 'default'): AbstractConnection
    {
        $conn = $this->connections[$name] ?? null;
        if (! $conn instanceof AbstractConnection || ! $this->isConnectionHealthy($conn)) {
            // Close old connection if it exists but is unhealthy
            if ($conn instanceof AbstractConnection) {
                try {
                    $conn->close();
                } catch (Throwable) {
                    // Ignore errors when closing stale connection
                }
            }
            
            /** @var array<string,mixed> $cfg */
            $cfg = $this->connectionDefinitions[$name] ?? $this->connectionDefinitions['default'] ?? [];
            $created = $this->factory->create($cfg);
            $this->connections[$name] = $created;
            return $created; // always AbstractConnection
        }
        return $conn; // guaranteed AbstractConnection
    }

    /**
     * @template T
     *
     * @param  callable(AMQPChannel):T  $fn
     * @return T
     */
    public function withChannel(callable $fn, string $connectionName = 'default')
    {
        $conn = $this->get($connectionName);
        // Always non-reuse: open, run, close each time — close channel and connection
        $channel = $conn->channel();
        try {
            return $fn($channel);
        } finally {
            try {
                if ($channel->is_open()) {
                    $channel->close();
                }
            } catch (Throwable) {
            }
            // Close the underlying connection as well to avoid connection growth
            try {
                if ($conn->isConnected()) {
                    $conn->close();
                }
            } catch (Throwable) {
            }
            // Remove from pool so next call creates a new fresh connection
            $this->connections[$connectionName] = null;
        }
    }

    public function reset(?string $name = null): void
    {
        if ($name === null) {
            // Only perform a global reset when explicitly requested. Passing null from internal calls
            // (e.g. batchPublish) should not accidentally clear all connections.
            foreach ($this->connections as $key => $conn) {
                if ($conn) {
                    try {
                        $conn->close();
                    } catch (Throwable) {
                    }
                    $this->connections[$key] = null;
                }
            }
            return;
        }
        $conn = $this->connections[$name] ?? null;
        if ($conn instanceof AbstractConnection) {
            try {
                $conn->close();
            } catch (Throwable) {
            }
            $this->connections[$name] = null;
        }
    }

    /**
     * Check if connection is healthy and usable.
     */
    private function isConnectionHealthy(AbstractConnection $conn): bool
    {
        try {
            if (! $conn->isConnected()) {
                return false;
            }

            // Try a lightweight operation to verify connection is truly alive
            // Note: This is conservative - if we can't verify, assume it's healthy
            // to avoid unnecessary reconnections
            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
