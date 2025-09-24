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
    /** @var string|null $name */
    $name = $this->argument('connection');
    $raw = config('amqp.connections', ['default' => []]);
    $candidates = is_array($raw) ? array_keys($raw) : ['default'];
    $names = $name ? [$name] : array_map('strval', $candidates);
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
