<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Factories;

use PhpAmqpLib\Connection\AMQPStreamConnection;

class ConnectionFactory
{
    public function create(array $base): AMQPStreamConnection
    {
        foreach (['host', 'port', 'user', 'password', 'vhost'] as $k) {
            if (! array_key_exists($k, $base)) {
                throw new \InvalidArgumentException("Missing AMQP connection config key: {$k}");
            }
        }

        $options = $base['options'] ?? [];
        $context = null;
        if (! empty($options['ssl'])) {
            $ssl = [];
            foreach (['cafile', 'local_cert', 'local_pk', 'verify_peer', 'passphrase'] as $k) {
                if (isset($options[$k])) {
                    $ssl[$k] = $options[$k];
                }
            }
            if ($ssl) {
                $context = stream_context_create(['ssl' => $ssl]);
            }
        }

        return new AMQPStreamConnection(
            host: $base['host'],
            port: (int) $base['port'],
            user: $base['user'],
            password: $base['password'],
            vhost: $base['vhost'],
            insist: false,
            login_method: 'AMQPLAIN',
            login_response: null,
            locale: 'en_US',
            connection_timeout: 3.0,
            read_write_timeout: 3.0,
            context: $context,
            keepalive: (bool) ($options['keepalive'] ?? true),
            heartbeat: (int) ($options['heartbeat'] ?? 60),
        );
    }
}
