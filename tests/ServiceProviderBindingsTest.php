<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Tests;

use Airygen\RabbitMQ\Contracts\MetricsExporterInterface;
use Airygen\RabbitMQ\RabbitMQServiceProvider;
use Airygen\RabbitMQ\Support\PrometheusMetricsExporter;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;

final class ServiceProviderBindingsTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [RabbitMQServiceProvider::class];
    }

    public function testResolvesMetricsExporter(): void
    {
        $exporter = $this->app->make(MetricsExporterInterface::class);
        $this->assertInstanceOf(PrometheusMetricsExporter::class, $exporter);
    }

    public function testCommandsRegistered(): void
    {
        $list = Artisan::all();
        $this->assertArrayHasKey('rabbitmq:metrics', $list);
        $this->assertArrayHasKey('rabbitmq:ping', $list);
    }
}
