<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Tests;

use Airygen\RabbitMQ\Contracts\ConnectionManagerInterface;
use Airygen\RabbitMQ\Contracts\ProducerPayloadInterface;
use Airygen\RabbitMQ\Factories\MessageFactory;
use Airygen\RabbitMQ\Publisher;
use Airygen\RabbitMQ\Support\SystemClock;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPIOException;
use PHPUnit\Framework\TestCase;

final class PublisherRetryTest extends TestCase
{
    /**
     * @phpcs:disable
     */
    public function testRetrySucceedsAfterTransientFailure(): void
    {
        $attempts = 0;

        $payload = new class(['x' => 1]) implements ProducerPayloadInterface
        {
            public function __construct(private array $d) {}

            public function getConnectionName(): ?string
            {
                return 'default';
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

        $clock = new class extends SystemClock {};
        $mf = new MessageFactory($clock, 'app', 'testing');

        $basicPublishCalls = 0;
        $published = [];
        $channel = $this->getMockBuilder(AMQPChannel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['confirm_select', 'basic_publish', 'wait_for_pending_acks_returns', 'is_open', 'close'])
            ->getMock();
        $channel->method('confirm_select')->willReturn(null);
        $channel->method('basic_publish')->willReturnCallback(function ($msg, $exchange = '', $routing_key = '') use (&$basicPublishCalls, &$published) {
            $basicPublishCalls++;
            if ($basicPublishCalls === 1) {
                throw new AMQPIOException('simulated transient IO failure');
            }
            $published[] = [$exchange, $routing_key, $msg->getBody()];

            return null;
        });
        $channel->method('wait_for_pending_acks_returns')->willReturn(true);
        $channel->method('is_open')->willReturn(true);
        $channel->method('close')->willReturn(null);

        $cm = $this->createMock(ConnectionManagerInterface::class);
        $cm->method('withChannel')->willReturnCallback(function ($fn) use ($channel, &$attempts) {
            $attempts++;

            return $fn($channel);
        });
        $cm->method('reset')->willReturnCallback(function () {});

        $pub = new Publisher($cm, $mf);
        $pub->publish($payload, retryTimes: 2, when: fn (\Throwable $e) => $e instanceof AMQPIOException);
        $this->assertSame(2, $basicPublishCalls, 'Should attempt twice (1st fails, 2nd succeeds)');
        $this->assertCount(1, $published);
    }

    public function testJsonSerializationFailureNotRetried(): void
    {
        $payload = new class(['stream' => fopen('php://memory', 'r')]) implements ProducerPayloadInterface
        {
            public function __construct(private array $d) {}

            public function getConnectionName(): ?string
            {
                return 'default';
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

        $clock = new class extends SystemClock {};
        $mf = new MessageFactory($clock, 'app', 'testing');

        $cm = $this->createMock(ConnectionManagerInterface::class);
        $cm->method('withChannel')->willReturnCallback(function ($fn) {
            $channel = $this->getMockBuilder(AMQPChannel::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['confirm_select', 'basic_publish', 'wait_for_pending_acks_returns', 'is_open', 'close'])
                ->getMock();
            $channel->method('confirm_select')->willReturn(null);
            $channel->method('basic_publish')->willReturn(null);
            $channel->method('wait_for_pending_acks_returns')->willReturn(true);
            $channel->method('is_open')->willReturn(true);
            $channel->method('close')->willReturn(null);

            return $fn($channel);
        });

        $pub = new Publisher($cm, $mf);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Payload serialization failed');
        $pub->publish($payload, retryTimes: 2, when: fn () => true);
    }

    public function testBatchPublishDifferentConnections(): void
    {
        $payload1 = new class(['a' => 1]) implements ProducerPayloadInterface
        {
            public function __construct(private array $d) {}

            public function getConnectionName(): ?string
            {
                return 'default';
            }

            public function getExchangeName(): ?string
            {
                return 'ex.one';
            }

            public function getRoutingKey(): ?string
            {
                return 'rk.one';
            }

            public function jsonSerialize(): mixed
            {
                return $this->d;
            }
        };
        $payload2 = new class(['b' => 2]) implements ProducerPayloadInterface
        {
            public function __construct(private array $d) {}

            public function getConnectionName(): ?string
            {
                return 'secondary';
            }

            public function getExchangeName(): ?string
            {
                return 'ex.two';
            }

            public function getRoutingKey(): ?string
            {
                return 'rk.two';
            }

            public function jsonSerialize(): mixed
            {
                return $this->d;
            }
        };

        $clock = new class extends SystemClock {};
        $mf = new MessageFactory($clock, 'app', 'testing');

        // Prepare two channel mocks capturing publish calls
        $channels = [];
        $publishedByChannel = ['default' => [], 'secondary' => []];
        foreach (['default', 'secondary'] as $name) {
            $mock = $this->getMockBuilder(AMQPChannel::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['confirm_select', 'basic_publish', 'wait_for_pending_acks_returns', 'is_open', 'close'])
                ->getMock();
            $mock->method('confirm_select')->willReturn(null);
            $mock->method('basic_publish')->willReturnCallback(function ($msg, $exchange = '', $routingKey = '') use (&$publishedByChannel, $name) {
                $publishedByChannel[$name][] = [$exchange, $routingKey];

                return null;
            });
            $mock->method('wait_for_pending_acks_returns')->willReturn(true);
            $mock->method('is_open')->willReturn(true);
            $mock->method('close')->willReturn(null);
            $channels[$name] = $mock;
        }

        $cm = $this->createMock(ConnectionManagerInterface::class);
        $cm->method('withChannel')->willReturnCallback(function ($fn, $connectionName = 'default') use (&$channels) {
            $ch = $channels[$connectionName] ?? $channels['default'];

            return $fn($ch);
        });

        $pub = new Publisher($cm, $mf);
        $pub->batchPublish([$payload1, $payload2]);

        $this->assertCount(1, $publishedByChannel['default']);
        $this->assertSame(['ex.one', 'rk.one'], $publishedByChannel['default'][0]);
        $this->assertCount(1, $publishedByChannel['secondary']);
        $this->assertSame(['ex.two', 'rk.two'], $publishedByChannel['secondary'][0]);
    }

    public function testNonTransientFailureNotRetried(): void
    {
        $attempts = 0;

        $payload = new class(['x' => 1]) implements ProducerPayloadInterface
        {
            public function __construct(private array $d) {}

            public function getConnectionName(): ?string
            {
                return 'default';
            }

            public function getExchangeName(): ?string
            {
                return 'ex.nontransient';
            }

            public function getRoutingKey(): ?string
            {
                return 'rk.nontransient';
            }

            public function jsonSerialize(): mixed
            {
                return $this->d;
            }
        };

        $clock = new class extends SystemClock {};
        $mf = new MessageFactory($clock, 'app', 'testing');

        $basicPublishCalls = 0;
        $channel = $this->getMockBuilder(AMQPChannel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['confirm_select', 'basic_publish', 'wait_for_pending_acks_returns', 'is_open', 'close'])
            ->getMock();
        $channel->method('confirm_select')->willReturn(null);
        $channel->method('basic_publish')->willReturnCallback(function () use (&$basicPublishCalls) {
            $basicPublishCalls++;
            throw new \RuntimeException('boom');
        });
        $channel->method('wait_for_pending_acks_returns')->willReturn(true);
        $channel->method('is_open')->willReturn(true);
        $channel->method('close')->willReturn(null);

        $cm = $this->createMock(ConnectionManagerInterface::class);
        $cm->method('withChannel')->willReturnCallback(function ($fn) use ($channel, &$attempts) {
            $attempts++;

            return $fn($channel);
        });
        $cm->method('reset')->willReturnCallback(function () {});

        $pub = new Publisher($cm, $mf);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');
        try {
            $pub->publish($payload, retryTimes: 5); // default transientWhen should NOT classify RuntimeException as transient
        } finally {
            $this->assertSame(1, $attempts, 'Should only attempt once for non-transient error');
            $this->assertSame(1, $basicPublishCalls, 'basic_publish should be called exactly once');
        }
    }
    /**
     * @phpcs:enable
     */
}
