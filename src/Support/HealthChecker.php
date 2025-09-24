<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Support;

use Airygen\RabbitMQ\Contracts\ConnectionManagerInterface;
use Throwable;

final class HealthChecker
{
    public function __construct(private ConnectionManagerInterface $manager)
    {
    }

    /**
     * @param  array<int,string>  $connectionNames
     * @return array<string,bool>
     */
    public function ping(array $connectionNames): array
    {
        $results = [];
        foreach ($connectionNames as $name) {
            if ($name === '') {
                continue; // skip invalid
            }
            try {
                $this->manager->withChannel(static function () {
                }, $name);
                $results[$name] = true;
            } catch (Throwable) {
                $results[$name] = false;
            }
        }

        return $results;
    }
}
