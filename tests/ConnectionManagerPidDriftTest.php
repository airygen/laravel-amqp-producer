<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Tests;

use Airygen\RabbitMQ\Contracts\PidProviderInterface;
use Airygen\RabbitMQ\Factories\ConnectionFactory;
use Airygen\RabbitMQ\Support\ConnectionManager;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PHPUnit\Framework\TestCase;

final class ConnectionManagerPidDriftTest extends TestCase
{
    public function testPidChangeTriggersGlobalReset(): void
    {
        $pids = [111, 111, 222, 222];
        $pidProvider = new class ($pids) implements PidProviderInterface
        {
            private array $pids;

            private int $i = 0;

            public function __construct(array $pids)
            {
                $this->pids = $pids;
            }

            public function getPid(): int
            {
                $index = min($this->i++, count($this->pids) - 1);

                return $this->pids[$index];
            }
        };

        // Fake connection & channel objects.
        $channel = $this->getMockBuilder(AMQPChannel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['is_open', 'close'])
            ->getMock();
        $channel->method('is_open')->willReturn(true);
        $channel->method('close')->willReturn(null);

        $connection = $this->getMockBuilder(AMQPStreamConnection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isConnected', 'channel', 'close'])
            ->getMock();
        $connection->method('isConnected')->willReturn(true);
        $connection->method('channel')->willReturn($channel);
        $connection->method('close')->willReturn(null);

        $factory = $this->getMockBuilder(ConnectionFactory::class)
            ->onlyMethods(['create'])
            ->getMock();
        $createCalls = 0;
        $factory->method('create')->willReturnCallback(
            function () use (&$createCalls, $connection) {
                $createCalls++;

                return $connection;
            }
        );

        $cfg = [
            'default' => [
                'host' => 'h',
                'port' => 5672,
                'user' => 'u',
                'password' => 'p',
                'vhost' => '/',
                'options' => [
                    'reuse_channel' => true,
                    'max_channel_uses' => 10,
                ],
            ],
            'options' => [
                'reuse_channel' => true,
                'max_channel_uses' => 10,
            ],
        ];
        $manager = new ConnectionManager($factory, $cfg, $pidProvider);

        // First access: pid = 111, create connection
        $manager->withChannel(fn (AMQPChannel $c) => null, 'default');
        // Second access: pid still 111 -> reuse
        $manager->withChannel(fn (AMQPChannel $c) => null, 'default');
        // Third access: pid changes to 222 -> triggers reset and new create
        $manager->withChannel(fn (AMQPChannel $c) => null, 'default');
        // Fourth access: pid stays 222 -> reuse
        $manager->withChannel(fn (AMQPChannel $c) => null, 'default');

        $this->assertSame(
            2,
            $createCalls,
            'Expected two connection creations (before and after PID drift)'
        );
    }
}
