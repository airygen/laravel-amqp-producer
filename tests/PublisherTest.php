<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Tests;

use Airygen\RabbitMQ\Config\ChannelResolver;
use Airygen\RabbitMQ\Connection\ConnectionManagerInterface;
use Airygen\RabbitMQ\Contracts\ProducerPayload;
use Airygen\RabbitMQ\Messaging\MessageFactory;
use Airygen\RabbitMQ\Producer\Publisher;
use Airygen\RabbitMQ\Support\Clock;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\TestCase;

final class PublisherTest extends TestCase
{
    public function test_publish_success_with_confirms(): void
    {
        $payload = new class(['foo' => 'bar']) implements ProducerPayload
        {
            public function __construct(private array $d) {}

            public function getConnectionName(): ?string
            {
                return 'member_events';
            }

            public function getRoutingKey(): ?string
            {
                return null;
            }

            public function jsonSerialize(): mixed
            {
                return $this->d;
            }
        };

        $clock = new class implements Clock
        {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2024-01-01T00:00:00+00:00');
            }
        };

        $mf = new MessageFactory($clock, 'test-app', 'testing');
        $resolver = new ChannelResolver([
            'channels' => [
                'member_events' => [
                    'exchange_name' => 'ex.test',
                    'routing_key' => 'rk.test',
                ],
            ],
        ]);

        $channel = new class extends AMQPChannel
        {
            public array $published = [];

            public function __construct() {}

            public function confirm_select($nowait = false) {}

            public function basic_publish($msg, $exchange = '', $routing_key = '', $mandatory = false, $immediate = false, $ticket = null)
            {
                if (!$msg instanceof AMQPMessage) {
                    throw new \InvalidArgumentException('Expected AMQPMessage instance');
                }

                $this->published[] = [$exchange, $routing_key, $mandatory, $msg->getBody()];
            }

            public function wait_for_pending_acks_returns($timeout = 0.0)
            {
                return true;
            }

            public function is_open()
            {
                return true;
            }

            public function close($reply_code = 0, $reply_text = '', $method_sig = [0, 0]) {}
        };

        $cm = $this->createMock(ConnectionManagerInterface::class);
        $cm->method('withChannel')->willReturnCallback(function ($fn) use ($channel) {
            return $fn($channel);
        });

        $pub = new Publisher($cm, $mf, $resolver);
        $pub->publish($payload);

        $this->assertCount(1, $channel->published);
        [$ex, $rk, $mandatory, $body] = $channel->published[0];
        $this->assertSame('ex.test', $ex);
        $this->assertSame('rk.test', $rk);
        $this->assertTrue($mandatory);
        $this->assertJson($body);
    }
}
