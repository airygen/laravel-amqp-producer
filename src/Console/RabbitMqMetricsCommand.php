<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Console;

use Airygen\RabbitMQ\Contracts\MetricsExporterInterface;
use Illuminate\Console\Command;

final class RabbitMqMetricsCommand extends Command
{
    protected $signature = 'rabbitmq:metrics {--raw : Output only metric lines (omit HELP/TYPE)}';

    protected $description = 'Output in-memory RabbitMQ producer metrics (Prometheus text format).';

    public function handle(MetricsExporterInterface $exporter): int
    {
        $output = $exporter->render();
        if ($this->option('raw')) {
            $lines = array_filter(explode("\n", $output), fn ($l) => $l !== '' && $l[0] !== '#');
            $output = implode("\n", $lines) . "\n";
        }
        $this->output->write($output);

        return self::SUCCESS;
    }
}
