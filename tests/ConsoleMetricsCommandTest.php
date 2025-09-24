<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Tests;

use Airygen\RabbitMQ\RabbitMQServiceProvider;
use Airygen\RabbitMQ\Support\Stats;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;

final class ConsoleMetricsCommandTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [RabbitMQServiceProvider::class];
    }

    public function testMetricsCommandOutputsHelpLines(): void
    {
        Stats::reset();
        Stats::incr('publish_attempts', 2);
        $code = Artisan::call('rabbitmq:metrics');
        $output = Artisan::output();
        $this->assertSame(0, $code);
        $this->assertStringContainsString(
            '# HELP rabbitmq_publish_attempts_total',
            $output
        );
        $metricLine = 'rabbitmq_publish_attempts_total 2';
        $this->assertStringContainsString($metricLine, $output);
    }

    public function testMetricsCommandRawOmitsComments(): void
    {
        Stats::reset();
        Stats::incr('publish_attempts', 1);
        Artisan::call('rabbitmq:metrics', ['--raw' => true]);
        $output = Artisan::output();
        $this->assertStringNotContainsString('# HELP', $output);
        $metricPrefix = 'rabbitmq_publish_attempts_total';
        $this->assertStringStartsWith($metricPrefix, trim($output));
    }
}
