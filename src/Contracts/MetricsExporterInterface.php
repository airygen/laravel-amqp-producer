<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Contracts;

interface MetricsExporterInterface
{
    /**
     * Render metrics in Prometheus text exposition format (UTF-8, newline terminated).
     */
    public function render(): string;
}
