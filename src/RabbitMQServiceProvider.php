<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ;

use Airygen\RabbitMQ\Contracts\ConnectionManagerInterface;
use Airygen\RabbitMQ\Contracts\ExceptionClassifierInterface;
use Airygen\RabbitMQ\Contracts\MetricsExporterInterface;
use Airygen\RabbitMQ\Contracts\PidProviderInterface;
use Airygen\RabbitMQ\Contracts\ProducerInterface;
use Airygen\RabbitMQ\Factories\ConnectionFactory;
use Airygen\RabbitMQ\Factories\MessageFactory;
use Airygen\RabbitMQ\Support\ConnectionManager;
use Airygen\RabbitMQ\Support\DefaultExceptionClassifier;
use Airygen\RabbitMQ\Support\PrometheusMetricsExporter;
use Airygen\RabbitMQ\Support\SystemClock;
use Airygen\RabbitMQ\Support\SystemPidProvider;
use Illuminate\Support\ServiceProvider;

final class RabbitMQServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/amqp.php', 'amqp');
        /** @var array<string,mixed>|null $amqpConfig */
        $amqpConfig = $this->app->make('config')->get('amqp');
        if (!is_array($amqpConfig)) {
            throw new \RuntimeException('Missing AMQP connections config');
        }

        $this->app->singleton(PidProviderInterface::class, SystemPidProvider::class);

        $this->app->singleton(
            ConnectionManager::class,
            fn ($app) => new ConnectionManager(
                $app->make(ConnectionFactory::class),
                $amqpConfig,
                $app->make(PidProviderInterface::class)
            )
        );
        $this->app->alias(
            ConnectionManager::class,
            ConnectionManagerInterface::class
        );

        $this->app->singleton(MessageFactory::class, function ($app) {
            $appName = (string) config('app.name', 'laravel');
            $env = (string) app()->environment();

            return new MessageFactory(
                clock: new SystemClock(),
                appName: $appName,
                env: $env,
            );
        });

        $this->app->singleton(ExceptionClassifierInterface::class, DefaultExceptionClassifier::class);

        $this->app->bind(ProducerInterface::class, function ($app) use ($amqpConfig) {
            $retry = $amqpConfig['retry'] ?? [];
            $logger = $app->has('log') ? $app->make('log') : null;

            return new Publisher(
                $app->make(ConnectionManagerInterface::class),
                $app->make(MessageFactory::class),
                $app->make(ExceptionClassifierInterface::class),
                $logger,
                $retry
            );
        });

        // Metrics exporter binding (Prometheus skeleton)
        $this->app->singleton(MetricsExporterInterface::class, PrometheusMetricsExporter::class);

        // Console command(s)
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Airygen\RabbitMQ\Console\RabbitMqPingCommand::class,
                \Airygen\RabbitMQ\Console\RabbitMqMetricsCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        $this->publishes(
            [
                __DIR__ . '/amqp.php' => config_path('amqp.php'),
            ],
            'config'
        );

        // Octane / Swoole / RoadRunner: ensure per-worker fresh state.
        $workerStarting = 'Laravel\\Octane\\Events\\WorkerStarting';
        $workerStopping = 'Laravel\\Octane\\Events\\WorkerStopping';
        if (class_exists($workerStarting) && class_exists($workerStopping) && $this->app->bound('events')) {
            $events = $this->app->make('events');
            $events->listen($workerStarting, function () {
                $this->app->make(ConnectionManager::class)->reset();
            });
            $events->listen($workerStopping, function () {
                $this->app->make(ConnectionManager::class)->reset();
            });
        }
    }
}
