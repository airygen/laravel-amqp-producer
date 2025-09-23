<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Messaging;

use Airygen\RabbitMQ\Contracts\ProducerPayload;
use Airygen\RabbitMQ\Support\Clock;
use Illuminate\Support\Str;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

final class MessageFactory
{
    public function __construct(private Clock $clock, private string $appName, private string $env) {}

    public function make(ProducerPayload $payload, ?array $givenHeader): AMQPMessage
    {
        $requestId = null;
        if (function_exists('request')) {
            try {
                $requestId = request()?->header('X-Request-Id');
            } catch (Throwable) {
                $requestId = null;
            }
        }

        $baseHeader = [
            'source' => $this->appName,
            'request_id' => $requestId ?? (string) Str::uuid(),
            'datetime' => $this->clock->now()->format(DATE_ATOM),
            'env' => $this->env,
        ];
        $header = $givenHeader ? array_merge($baseHeader, $givenHeader) : $baseHeader;

        return new AMQPMessage(
            body: json_encode($payload, JSON_THROW_ON_ERROR),
            properties: [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'application_headers' => new AMQPTable($header),
            ]
        );
    }
}
