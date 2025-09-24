<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Contracts;

use Throwable;

interface ExceptionClassifierInterface
{
    public function isTransient(Throwable $e): bool;
}
