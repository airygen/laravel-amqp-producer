<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Facades;

use Airygen\RabbitMQ\Contracts\ProducerInterface;
use Airygen\RabbitMQ\Contracts\ProducerPayload;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void publish(ProducerPayload $payload, array $header = null, int $retryTimes = null, callable $when = null)
 * @method static void batchPublish(array $payloads, array $header = null, int $retryTimes = null, callable $when = null)
 */
final class RabbitMQProducer extends Facade
{
    protected static function getFacadeAccessor()
    {
        return ProducerInterface::class;
    }
}
