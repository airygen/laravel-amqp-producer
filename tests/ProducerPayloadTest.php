<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Tests;

use Airygen\RabbitMQ\ProducerPayload;
use PHPUnit\Framework\TestCase;

final class ProducerPayloadTest extends TestCase
{
    public function testDefaultsAndJson(): void
    {
        $payload = new class (['a' => 1]) extends ProducerPayload
        {
            protected string $connectionName = 'secondary';

            protected ?string $exchangeName = 'ex.bus';

            protected ?string $routingKey = 'rk.abc';
        };
        $this->assertSame('secondary', $payload->getConnectionName());
        $this->assertSame('ex.bus', $payload->getExchangeName());
        $this->assertSame('rk.abc', $payload->getRoutingKey());
        $this->assertSame(['a' => 1], $payload->jsonSerialize());
    }
}
