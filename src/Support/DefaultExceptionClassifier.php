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
        if ($e instanceof AMQPIOException) {
            return true;
        }
        if ($e instanceof AMQPProtocolChannelException) { // more specific before parent
            return true;
        }
        return $e instanceof AMQPProtocolException;
    }
}
