<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Support;

final class Stats
{
    private static array $counters = [
        'publish_attempts' => 0,
        'publish_retries' => 0,
        'publish_failures' => 0,
        'connection_resets' => 0,
        // per-connection metrics stored under keys like per_connection => [connection => [metric => value]]
        'per_connection' => [],
    ];

    public static function incr(string $key, int $by = 1): void
    {
        if (! isset(self::$counters[$key])) {
            self::$counters[$key] = 0;
        }
        self::$counters[$key] += $by;
    }

    public static function snapshot(): array
    {
        return self::$counters;
    }

    public static function incrConnection(string $connection, string $metric, int $by = 1): void
    {
        if (! isset(self::$counters['per_connection'][$connection])) {
            self::$counters['per_connection'][$connection] = [];
        }
        if (! isset(self::$counters['per_connection'][$connection][$metric])) {
            self::$counters['per_connection'][$connection][$metric] = 0;
        }
        self::$counters['per_connection'][$connection][$metric] += $by;
    }

    public static function reset(): void
    {
        foreach (self::$counters as $k => $_) {
            if ($k === 'per_connection') {
                self::$counters[$k] = [];

                continue;
            }
            self::$counters[$k] = 0;
        }
    }
}
