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
        if ($key === 'per_connection') {
            // per_connection is a structured sub-array; ignore direct scalar increments
            return;
        }
        if (! array_key_exists($key, self::$counters)) {
            // ignore unknown keys to keep shape stable
            return;
        }
        if (!is_int(self::$counters[$key])) {
            return; // safeguard against unexpected shape
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
        if (! isset(self::$counters['per_connection']) || ! is_array(self::$counters['per_connection'])) {
            self::$counters['per_connection'] = [];
        }
        if (! isset(self::$counters['per_connection'][$connection])) {
            self::$counters['per_connection'][$connection] = [];
        }
        $cur = self::$counters['per_connection'][$connection][$metric] ?? 0;
        self::$counters['per_connection'][$connection][$metric] = $cur + $by;
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
