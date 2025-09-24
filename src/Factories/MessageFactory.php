<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Factories;

use Airygen\RabbitMQ\Contracts\ClockInterface;
use Airygen\RabbitMQ\Contracts\ProducerPayloadInterface;
use Illuminate\Support\Str;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

final class MessageFactory
{
    public function __construct(private ClockInterface $clock, private string $appName, private string $env)
    {
    }

    /**
     * @param array<string,mixed>|null $headers
     */
    public function make(ProducerPayloadInterface $payload, ?array $headers): AMQPMessage
    {
        $requestId = null;
        if (function_exists('request')) {
            try {
                /** @var \Illuminate\Http\Request|null $r */
                $r = request();
                $requestId = $r ? $r->header('X-Request-Id') : null;
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
        $header = $headers ? array_merge($baseHeader, $headers) : $baseHeader;

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // Non-retriable: the payload is not serializable
            throw new \RuntimeException('Payload serialization failed', 0, $e);
        }

        return new AMQPMessage(
            body: $json,
            properties: [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'application_headers' => new AMQPTable($header),
            ]
        );
    }
}
