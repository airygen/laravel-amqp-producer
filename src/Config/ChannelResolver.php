<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Config;

use Airygen\RabbitMQ\Contracts\ProducerPayload;
use InvalidArgumentException;

/**
 * 在 RabbitMQServiceProvider 中定義從 config/queue.php 取得設定
 */
final class ChannelResolver
{
    /**
     * Undocumented function
     *
     * @param  array  $config  Configuration should be injectged from provider.
     */
    public function __construct(private array $config)
    {
    }

    /**
     * Undocumented function
     */
    public function resolve(ProducerPayload $payload): array
    {
        $name = $payload->getConnectionName() ?? 'rabbitmq';
        $channels = $this->config['channels'] ?? [];
        if (! isset($channels[$name])) {
            throw new InvalidArgumentException("Channel config '{$name}' not found.");
        }

        return $channels[$name];
    }
}
