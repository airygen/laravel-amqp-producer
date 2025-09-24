<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Tests;

use Airygen\RabbitMQ\Contracts\ClockInterface;
use Airygen\RabbitMQ\Contracts\ConnectionManagerInterface;
use Airygen\RabbitMQ\Contracts\ProducerPayloadInterface;
use Airygen\RabbitMQ\Factories\MessageFactory;
use Airygen\RabbitMQ\Publisher;
use Airygen\RabbitMQ\Support\Stats;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PHPUnit\Framework\TestCase;

final class PublisherAckFailureTest extends TestCase
{
    public function testAckFailureRaisesExceptionAndCounts(): void
    {
        $payload = new class (['x' => 1]) implements ProducerPayloadInterface
        {
            public function getConnectionName(): ?string
            {
                return 'default';
            }

            public function getExchangeName(): ?string
            {
                return '';
            }

            public function getRoutingKey(): ?string
            {
                return '';
            }

            public function jsonSerialize(): mixed
            {
                return ['x' => 1];
            }
        };

        $clock = new class implements ClockInterface
        {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable();
            }
        };
        $mf = new MessageFactory($clock, 'app', 'testing');
        $cm = new class implements ConnectionManagerInterface
        {
            public function withChannel(callable $fn, string $connectionName = 'default')
            {
                $channel = new class extends AMQPChannel
                {
                    public function __construct()
                    {
                    }

                    // @phpcs:ignore PSR1.Methods.CamelCapsMethodName
                    public function confirm_select($nowait = false)
                    {
                    }

                    // @phpcs:ignore PSR1.Methods.CamelCapsMethodName
                    public function basic_publish(
                        $msg,
                        $ex = '',
                        $rk = '',
                        $mandatory = false,
                        $immediate = false,
                        $ticket = null
                    ) {
                    }

                    // @phpcs:ignore PSR1.Methods.CamelCapsMethodName
                    public function wait_for_pending_acks_returns(
                        $timeout = 0.0
                    ) {
                        return false; // force failure
                    }

                    // @phpcs:ignore PSR1.Methods.CamelCapsMethodName
                    public function is_open()
                    {
                        return true;
                    }

                    public function close(
                        $reply_code = 0,
                        $reply_text = '',
                        $method_sig = [0, 0]
                    ) {
                    }
                };

                return $fn($channel);
            }

            public function get(string $name = 'default'): AbstractConnection
            {
                return new class extends AbstractConnection
                {
                    public function __construct()
                    {
                    }

                    public function connect()
                    {
                    }

                    public function channel($channel_id = null)
                    {
                    }

                    public function close(
                        $reply_code = 0,
                        $reply_text = '',
                        $method_sig = [0, 0]
                    ) {
                    }

                    public function reconnect()
                    {
                    }
                };
            }

            public function reset(?string $name = null): void
            {
            }
        };

        Stats::reset();
        $publisher = new Publisher(
            $cm,
            $mf,
            retryConfig: [
                'base_delay' => 0.001,
                'max_delay' => 0.002,
                'jitter' => false,
            ]
        );
        $this->expectException(\RuntimeException::class);
        try {
            $publisher->publish(
                $payload,
                retryTimes: 1,
                when: fn () => false
            ); // no retry
        } finally {
            $snapshot = Stats::snapshot();
            $this->assertSame(1, $snapshot['publish_attempts']);
            $this->assertSame(1, $snapshot['publish_failures']);
        }
    }
}
