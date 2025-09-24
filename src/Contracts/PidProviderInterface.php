<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Contracts;

interface PidProviderInterface
{
    public function getPid(): int;
}
