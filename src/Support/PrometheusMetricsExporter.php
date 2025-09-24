<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Support;

use Airygen\RabbitMQ\Contracts\MetricsExporterInterface;

/**
 * Minimal, dependency-free Prometheus exposition renderer.
 * NOTE: In production you might prefer a full library like promphp/prometheus_client_php.
 */
final class PrometheusMetricsExporter implements MetricsExporterInterface
{
    private const METRICS = [
        'publish_attempts' => 'Total publish attempts (before confirm).',
        'publish_retries' => 'Number of retried publish attempts.',
        'publish_failures' => 'Number of failed publish operations after retries.',
        'connection_resets' => 'Number of connection resets triggered by the producer layer.',
    ];

    public function render(): string
    {
        $snapshot = Stats::snapshot();
        $lines = [];

        // Global counters
        foreach (self::METRICS as $key => $help) {
            if (! array_key_exists($key, $snapshot)) {
                continue;
            }
            $metricName = $this->metricName($key);
            $lines[] = sprintf('# HELP %s %s', $metricName, $help);
            $lines[] = sprintf('# TYPE %s counter', $metricName);
            $lines[] = sprintf('%s %d', $metricName, (int) $snapshot[$key]);
        }

        // Per-connection metrics (dynamic) - we only know names at runtime.
        if (isset($snapshot['per_connection']) && is_array($snapshot['per_connection'])) {
            foreach ($snapshot['per_connection'] as $connection => $metrics) {
                foreach ($metrics as $mKey => $value) {
                    $metricName = $this->metricName('connection_' . $mKey);
                    $lines[] = sprintf('# HELP %s Per-connection metric (%s).', $metricName, $mKey);
                    $lines[] = sprintf('# TYPE %s counter', $metricName);
                    $lines[] = sprintf(
                        '%s{connection="%s"} %d',
                        $metricName,
                        $this->escapeLabel($connection),
                        (int) $value
                    );
                }
            }
        }

        return implode("\n", $lines) . "\n"; // final newline required by Prometheus text format
    }

    private function metricName(string $raw): string
    {
        return 'rabbitmq_' . strtolower(preg_replace('/[^a-zA-Z0-9_]+/', '_', $raw)) . '_total';
    }

    private function escapeLabel(string $value): string
    {
        // Basic escaping per Prometheus text format spec
        return addcslashes($value, "\\\n\"");
    }
}
