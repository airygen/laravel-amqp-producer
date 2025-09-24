<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Tests;

use Airygen\RabbitMQ\Support\ConnectionManager;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PHPUnit\Framework\TestCase;

final class ConnectionManagerChannelReuseTest extends TestCase
{
    private function makeManager(int $maxUses, bool $reuse): array
    {
        $factory = $this->getMockBuilder(
            \Airygen\RabbitMQ\Factories\ConnectionFactory::class
        )
            ->onlyMethods(['create'])
            ->getMock();
        $metrics = (object) [
            'channelsCreated' => 0,
        ];
        $factory->method('create')->willReturnCallback(
            function () use ($metrics) {
                $conn = $this->getMockBuilder(AMQPStreamConnection::class)
                    ->disableOriginalConstructor()
                    ->onlyMethods(['channel', 'close', 'isConnected'])
                    ->getMock();
                $conn->method('isConnected')->willReturn(true);
                $conn->method('close')->willReturn(null);
                $conn->method('channel')->willReturnCallback(
                    function () use ($metrics) {
                        $metrics->channelsCreated++;
                        $ch = $this->getMockBuilder(AMQPChannel::class)
                            ->disableOriginalConstructor()
                            ->onlyMethods(['is_open', 'close'])
                            ->getMock();
                        $ch->method('is_open')->willReturn(true);
                        $ch->method('close')->willReturn(null);

                        return $ch;
                    }
                );

                return $conn; // correct subclass for return type
            }
        );
        $config = [
            'connections' => [
                'default' => [
                    'host' => 'h',
                    'port' => 5672,
                    'user' => 'u',
                    'password' => 'p',
                    'vhost' => '/',
                    'options' => [
                        'reuse_channel' => $reuse,
                        'max_channel_uses' => $maxUses,
                    ],
                ],
            ],
        ];
        $manager = new ConnectionManager($factory, $config);

        return [$manager, $metrics];
    }

    public function testChannelReuseRotation(): void
    {
        [$m, $metrics] = $this->makeManager(2, true);
        // Use channel 3 times; max uses =2 so third call rotates
        for ($i = 0; $i < 3; $i++) {
            $m->withChannel(static fn () => null, 'default');
        }
        $this->assertSame(
            2,
            $metrics->channelsCreated,
            'Should create 2 channels (initial + rotated)'
        );
    }

    public function testChannelReuseDisabled(): void
    {
        [$m, $metrics] = $this->makeManager(100, false);
        for ($i = 0; $i < 3; $i++) {
            $m->withChannel(static fn () => null, 'default');
        }
        $this->assertSame(
            3,
            $metrics->channelsCreated,
            'No reuse => new channel each invocation'
        );
    }
}
