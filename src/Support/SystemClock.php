<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Support;

use Airygen\RabbitMQ\Contracts\ClockInterface;
use DateTimeImmutable;

class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now');
    }
}
