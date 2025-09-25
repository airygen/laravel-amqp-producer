<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Tests;

use Airygen\RabbitMQ\Factories\ConnectionFactory;
use Airygen\RabbitMQ\Support\ConnectionManager;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection as StreamConnection;
use PHPUnit\Framework\TestCase;

final class ConnectionManagerDisposeTest extends TestCase
{
    public function testDisposesClosedChannelOnNextAcquire(): void
    {
        // Fake factory returning stub connection with controllable channel
        $channels = [];
        $factory = new class ($channels) extends ConnectionFactory
        {
            public function __construct(private array &$channelsStore)
            {
            }

            public function create(array $base): StreamConnection
            {
                return new class ($this->channelsStore) extends StreamConnection
                {
                    public function __construct(private array &$channelsStore)
                    {
                    }

                    public function channel($channel_id = null)
                    {
                        $ch = new class extends AMQPChannel
                        {
                            private bool $open = true;

                            public function __construct()
                            {
                            }

                            // @phpcs:ignore PSR1.Methods.CamelCapsMethodName
                            public function is_open()
                            {
                                return $this->open;
                            }

                            public function forceClose(): void
                            {
                                $this->open = false;
                            }

                            public function close(
                                $reply_code = 0,
                                $reply_text = '',
                                $method_sig = [0, 0]
                            ) {
                                $this->open = false;
                            }
                        };
                        $this->channelsStore[] = $ch;

                        return $ch;
                    }
                };
            }
        };

        $config = [
            'connections' => [
                'default' => [
                    'host' => 'h',
                    'port' => 5672,
                    'user' => 'u',
                    'password' => 'p',
                    'vhost' => '/',
                ],
            ],
            'options' => [],
        ];

        $cm = new ConnectionManager($factory, $config);

        $captured = null;
        $cm->withChannel(function ($ch) use (&$captured) {
            $captured = $ch;
        });
        // Simulate external close of channel without manager knowledge (stale)
        if (is_object($captured) && method_exists($captured, 'forceClose')) {
            $captured->forceClose();
        }
        // Second acquisition should detect stale and create a new channel
        $second = null;
        $cm->withChannel(function ($ch) use (&$second) {
            $second = $ch;
        });
        $this->assertNotSame(
            $captured,
            $second,
            'Expected channel to be recycled after stale detection'
        );
    }
}
