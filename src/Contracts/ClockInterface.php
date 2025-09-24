<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Contracts;

use DateTimeImmutable;

interface ClockInterface
{
    public function now(): DateTimeImmutable;
}
