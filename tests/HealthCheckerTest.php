<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Tests;

use Airygen\RabbitMQ\Contracts\ConnectionManagerInterface;
use Airygen\RabbitMQ\Support\HealthChecker;
use PhpAmqpLib\Channel\AMQPChannel;
use PHPUnit\Framework\TestCase;

final class HealthCheckerTest extends TestCase
{
    public function testPingAllOk(): void
    {
        $channel = $this->getMockBuilder(AMQPChannel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['is_open', 'close'])
            ->getMock();
        $channel->method('is_open')->willReturn(true);
        $channel->method('close')->willReturn(null);
        $manager = new class ($channel) implements ConnectionManagerInterface
        {
            public function __construct(private AMQPChannel $ch)
            {
            }

            public function get(): \PhpAmqpLib\Connection\AbstractConnection
            {
                throw new \LogicException('unused');
            }

            public function withChannel(callable $fn)
            {
                return $fn($this->ch);
            }

            public function reset(): void
            {
            }
        };
        $hc = new HealthChecker($manager);
        $res = $hc->ping(['default', 'secondary']);
        $this->assertSame(['default' => true, 'secondary' => true], $res);
    }

    public function testPingFailure(): void
    {
        $manager = new class implements ConnectionManagerInterface
        {
            public function get(): \PhpAmqpLib\Connection\AbstractConnection
            {
                throw new \LogicException('unused');
            }

            public function withChannel(callable $fn)
            {
                throw new \RuntimeException('fail');
            }

            public function reset(): void
            {
            }
        };
        $hc = new HealthChecker($manager);
        $res = $hc->ping(['default']);
        $this->assertSame(['default' => false], $res);
    }
}
