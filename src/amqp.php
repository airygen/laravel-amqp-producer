<?php

return [
    'retry' => [
        'base_delay' => 0.2,      // seconds
        'max_delay' => 1.5,       // seconds
        'jitter' => false,        // enable randomization of backoff delay
    ],
    'connections' => [
        'default' => [
            'host' => env('AMQP_HOST', '127.0.0.1'),
            'port' => (int) env('AMQP_PORT', 5672),
            'user' => env('AMQP_USER', 'guest'),
            'password' => env('AMQP_PASSWORD', 'guest'),
            'vhost' => env('AMQP_VHOST', '/'),
            'options' => [
                'lazy' => true,
                'keepalive' => true,
                'heartbeat' => 60,
                'reuse_channel' => true,
                'max_channel_uses' => 5000,
                // TLS / SSL (optional)
                // 'ssl' => true,
                // 'cafile' => base_path('certs/ca.pem'),
                // 'local_cert' => base_path('certs/client.pem'),
                // 'local_pk' => base_path('certs/client.key'),
                // 'verify_peer' => true,
            ],
        ],
    ],
];
