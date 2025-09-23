<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Connection;

use PhpAmqpLib\Connection\AMQPStreamConnection;

final class ConnectionFactory
{
    public function __construct(private array $base)
    {
    }

    public function create(): AMQPStreamConnection
    {
        return new AMQPStreamConnection(
            host: $this->base['host'],
            port: (int) $this->base['port'],
            user: $this->base['user'],
            password: $this->base['password'],
            vhost: $this->base['vhost'],
            insist: false,
            login_method: 'AMQPLAIN',
            login_response: null,
            locale: 'en_US',
            connection_timeout: 3.0,
            read_write_timeout: 3.0,
            context: null,
            keepalive: (bool) ($this->base['options']['keepalive'] ?? true),
            heartbeat: (int) ($this->base['options']['heartbeat'] ?? 60),
        );
    }
}
