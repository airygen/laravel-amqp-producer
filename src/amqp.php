<?php

return [
    'retry' => [
        'base_delay' => 0.2,      // seconds
        'max_delay' => 1.5,       // seconds
        'jitter' => false,        // enable randomization of backoff delay
        // Publisher confirm timeout (in seconds). Increase in high-latency environments.
        'confirm_timeout' => (float) env('AMQP_CONFIRM_TIMEOUT', 10.0),
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
                'heartbeat' => (int) env('AMQP_HEARTBEAT', 60),
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
