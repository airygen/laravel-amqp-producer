<?php

return [
    'base_connection' => [
        'host' => env('AMQP_HOST', '127.0.0.1'),
        'port' => (int) env('AMQP_PORT', 5672),
        'user' => env('AMQP_USER', 'guest'),
        'password' => env('AMQP_PASSWORD', 'guest'), // do not log
        'vhost' => env('AMQP_VHOST', '/'),
        'options' => [
            'lazy' => true,
            'keepalive' => true,
            'heartbeat' => 60,
        ],
    ],

    // logical channels
    'channels' => [
        'member_events' => [
            'exchange_name' => env('AMQP_EXCHANGE_MEMBER', 'member.ex'),
            'routing_key' => env('AMQP_RK_MEMBER_CREATED', 'member.created'),
        ],
    ],

    // consumer defaults
    'consumer' => [
        'prefetch' => 50,
        // handling policy for unexpected exceptions: 'requeue' or 'drop'
        'unexpected' => 'drop',
    ],
];
