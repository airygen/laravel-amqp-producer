<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Support;

/**
 * @phpstan-type StatsCounters array{
 *   publish_attempts:int,
 *   publish_retries:int,
 *   publish_failures:int,
 *   connection_resets:int,
 *   per_connection: array<string,array<string,int>>
 * }
 */
final class Stats
{
    /** @var list<string> */
    private const SCALAR_COUNTER_KEYS = [
        'publish_attempts',
        'publish_retries',
        'publish_failures',
        'connection_resets',
    ];

    /** @var StatsCounters */
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
        if (! in_array($key, self::SCALAR_COUNTER_KEYS, true)) {
            return; // ignore unknown keys and structured counters
        }
        self::$counters[$key] += $by;
    }

    /**
     * @return array{
     *   publish_attempts:int,
     *   publish_retries:int,
     *   publish_failures:int,
     *   connection_resets:int,
     *   per_connection: array<string,array<string,int>>
     * }
     */
    public static function snapshot(): array
    {
        /** @var array{
         *   publish_attempts:int,
         *   publish_retries:int,
         *   publish_failures:int,
         *   connection_resets:int,
         *   per_connection: array<string,array<string,int>>
         * } $copy
         */
        $copy = self::$counters;
        return $copy;
    }

    /**
     * @param non-empty-string $connection
     * @param non-empty-string $metric
     */
    public static function incrConnection(string $connection, string $metric, int $by = 1): void
    {
        $bucket = self::$counters['per_connection'][$connection] ?? [];
        $bucket[$metric] = ($bucket[$metric] ?? 0) + $by;
        self::$counters['per_connection'][$connection] = $bucket;
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
