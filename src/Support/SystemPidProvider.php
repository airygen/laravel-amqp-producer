<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Support;

use Airygen\RabbitMQ\Contracts\PidProviderInterface;

final class SystemPidProvider implements PidProviderInterface
{
    public function getPid(): int
    {
        return getmypid();
    }
}
