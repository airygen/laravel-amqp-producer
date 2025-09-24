<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Tests;

use Airygen\RabbitMQ\Contracts\ClockInterface;
use Airygen\RabbitMQ\Contracts\ConnectionManagerInterface;
use Airygen\RabbitMQ\Contracts\ProducerPayloadInterface as PayloadInterface;
use Airygen\RabbitMQ\Factories\MessageFactory;
use Airygen\RabbitMQ\Publisher;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;

final class PublisherTest extends TestCase
{
    public function testPublishSuccessWithConfirmsMultiConnection(): void
    {
        $payload1 = new class (['foo' => 'bar']) implements PayloadInterface
        {
            public function __construct(private array $d)
            {
            }

            public function getConnectionName(): ?string
            {
                return 'member_events';
            }

            public function getExchangeName(): ?string
            {
                return 'ex.test';
            }

            public function getRoutingKey(): ?string
            {
                return 'rk.test';
            }

            public function jsonSerialize(): mixed
            {
                return $this->d;
            }
        };
        $payload2 = new class (['baz' => 'qux']) implements PayloadInterface
        {
            public function __construct(private array $d)
            {
            }

            public function getConnectionName(): ?string
            {
                return 'other_events';
            }

            public function getExchangeName(): ?string
            {
                return 'ex.other';
            }

            public function getRoutingKey(): ?string
            {
                return 'rk.other';
            }

            public function jsonSerialize(): mixed
            {
                return $this->d;
            }
        };

        $clock = new class implements ClockInterface
        {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable(
                    '2024-01-01T00:00:00+00:00'
                );
            }
        };
        $mf = new MessageFactory($clock, 'test-app', 'testing');

        $channels = [
            'member_events' => $this->mockAMQPChannel(),
            'other_events' => $this->mockAMQPChannel(),
        ];
        $cm = $this->createMock(ConnectionManagerInterface::class);
        $cm->method('withChannel')->willReturnCallback(
            function ($fn, $connectionName = 'default') use (&$channels) {
                $channel = $channels[$connectionName]
                    ?? $channels['member_events'];

                return $fn($channel);
            }
        );

        $pub = new Publisher($cm, $mf);
        $pub->publish($payload1);
        $pub->publish($payload2);

        $this->assertCount(1, $channels['member_events']->published);
        $firstPublished = $channels['member_events']->published[0];
        [$ex1, $rk1, $mandatory1, $body1] = $firstPublished;
        $this->assertSame('ex.test', $ex1);
        $this->assertSame('rk.test', $rk1);
        $this->assertTrue($mandatory1);
        $this->assertJson($body1);

        $this->assertCount(1, $channels['other_events']->published);
        $secondPublished = $channels['other_events']->published[0];
        [$ex2, $rk2, $mandatory2, $body2] = $secondPublished;
        $this->assertSame('ex.other', $ex2);
        $this->assertSame('rk.other', $rk2);
        $this->assertTrue($mandatory2);
        $this->assertJson($body2);
    }

    /**
     * @phpcs:disable
     */
    private function mockAMQPChannel()
    {
        return new class extends AMQPChannel
        {
            public array $published = [];

            public function __construct() {}

            public function confirm_select($nowait = false) {}

            public function basic_publish(
                $msg,
                $exchange = '',
                $routing_key = '',
                $mandatory = false,
                $immediate = false,
                $ticket = null
            )
            {
                if (! $msg instanceof AMQPMessage) {
                    throw new InvalidArgumentException(
                        'Expected AMQPMessage instance'
                    );
                }

                $this->published[] = [
                    $exchange,
                    $routing_key,
                    $mandatory,
                    $msg->getBody(),
                ];
            }

            public function wait_for_pending_acks_returns($timeout = 0.0)
            {
                return true;
            }

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
    }
}
