<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Tests;

use Airygen\RabbitMQ\Support\PrometheusMetricsExporter;
use Airygen\RabbitMQ\Support\Stats;
use PHPUnit\Framework\TestCase;

final class MetricsExporterTest extends TestCase
{
    public function testRenderPrometheusMetrics(): void
    {
        Stats::reset();
        Stats::incr('publish_attempts', 3);
        Stats::incr('publish_retries', 1);
        Stats::incr('publish_failures', 2);
        Stats::incr('connection_resets', 1);
        Stats::incrConnection('primary', 'publish_attempts', 2);
        Stats::incrConnection('secondary', 'publish_attempts', 5);

        $exporter = new PrometheusMetricsExporter();
        $out = $exporter->render();

        $attempts = 'rabbitmq_publish_attempts_total 3';
        $retries = 'rabbitmq_publish_retries_total 1';
        $failures = 'rabbitmq_publish_failures_total 2';
        $resets = 'rabbitmq_connection_resets_total 1';
        $this->assertStringContainsString($attempts, $out);
        $this->assertStringContainsString($retries, $out);
        $this->assertStringContainsString($failures, $out);
        $this->assertStringContainsString($resets, $out);
        $primaryMetric = 'rabbitmq_connection_publish_attempts_total'
            . '{connection="primary"} 2';
        $secondaryMetric = 'rabbitmq_connection_publish_attempts_total'
            . '{connection="secondary"} 5';
        $this->assertStringContainsString($primaryMetric, $out);
        $this->assertStringContainsString($secondaryMetric, $out);
    }
}
