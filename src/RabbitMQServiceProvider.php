<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ;

use Airygen\RabbitMQ\Config\ChannelResolver;
use Airygen\RabbitMQ\Connection\ConnectionFactory;
use Airygen\RabbitMQ\Connection\ConnectionManager;
use Airygen\RabbitMQ\Connection\ConnectionManagerInterface;
use Airygen\RabbitMQ\Contracts\ProducerInterface;
use Airygen\RabbitMQ\Messaging\MessageFactory;
use Airygen\RabbitMQ\Producer\Publisher;
use Airygen\RabbitMQ\Support\SystemClock;
use Illuminate\Support\ServiceProvider;

final class RabbitMQServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/amqp.php', 'amqp');

        $this->app->singleton(ConnectionFactory::class, fn ($app) => new ConnectionFactory($app['config']['amqp']['base_connection'] ?? [])
        );

        $this->app->singleton(ConnectionManager::class, fn ($app) => new ConnectionManager($app->make(ConnectionFactory::class))
        );
        $this->app->alias(ConnectionManager::class, ConnectionManagerInterface::class);

        $this->app->singleton(ChannelResolver::class, fn ($app) => new ChannelResolver($app['config']['amqp'])
        );

        $this->app->singleton(MessageFactory::class, fn ($app) => new MessageFactory(
            clock: new SystemClock,
            appName: config('app.name', 'laravel'),
            env: app()->environment(),
        )
        );

        $this->app->bind(ProducerInterface::class, Publisher::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/amqp.php' => config_path('amqp.php'),
        ], 'config');
    }
}
