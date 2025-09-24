<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Support;

use Airygen\RabbitMQ\Contracts\ExceptionClassifierInterface;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Exception\AMQPProtocolException;
use Throwable;

final class DefaultExceptionClassifier implements ExceptionClassifierInterface
{
    public function isTransient(Throwable $e): bool
    {
        return $e instanceof AMQPIOException
            || $e instanceof AMQPProtocolException
            || $e instanceof AMQPProtocolChannelException;
    }
}
