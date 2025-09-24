<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Console;

use Airygen\RabbitMQ\Contracts\ConnectionManagerInterface;
use Airygen\RabbitMQ\Support\HealthChecker;
use Illuminate\Console\Command;

final class RabbitMqPingCommand extends Command
{
    protected $signature = 'rabbitmq:ping {connection? : Connection name (omit to test all)}';

    protected $description = 'Check RabbitMQ connectivity (open connection + channel)';

    public function handle(ConnectionManagerInterface $manager): int
    {
        $name = $this->argument('connection');
        $names = $name ? [$name] : array_keys(config('amqp.connections', ['default' => []]));
        $checker = new HealthChecker($manager);
        $results = $checker->ping($names);
        $ok = true;
        foreach ($results as $n => $status) {
            if ($status) {
                $this->line("[OK] {$n}");
            } else {
                $ok = false;
                $this->error("[FAIL] {$n}");
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
