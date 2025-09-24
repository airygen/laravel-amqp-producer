<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Tests;

use Airygen\RabbitMQ\Contracts\ConnectionManagerInterface;
use Airygen\RabbitMQ\Contracts\ExceptionClassifierInterface;
use Airygen\RabbitMQ\Contracts\ProducerPayloadInterface;
use Airygen\RabbitMQ\Factories\MessageFactory;
use Airygen\RabbitMQ\Publisher;
use Airygen\RabbitMQ\Support\SystemClock;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPIOException;
use PHPUnit\Framework\TestCase;

final class PublisherClassifierJitterTest extends TestCase
{
    public function testCustomClassifierEnablesRetryOnRuntimeException(): void
    {
        $payload = new class (['x' => 1]) implements ProducerPayloadInterface
        {
            public function __construct(private array $d)
            {
            }

            public function getConnectionName(): ?string
            {
                return 'default';
            }

            public function getExchangeName(): ?string
            {
                return 'ex';
            }

            public function getRoutingKey(): ?string
            {
                return 'rk';
            }

            public function jsonSerialize(): mixed
            {
                return $this->d;
            }
        };

        $mf = new MessageFactory(
            new class extends SystemClock {
            },
            'app',
            'testing'
        );

        $attempts = 0;
        $channel = $this->getMockBuilder(AMQPChannel::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'confirm_select',
                'basic_publish',
                'wait_for_pending_acks_returns',
                'is_open',
                'close',
            ])
            ->getMock();
        $channel->method('confirm_select')->willReturn(null);
        $channel->method('basic_publish')->willReturnCallback(
            function () use (&$attempts) {
                $attempts++;
                if ($attempts < 2) {
                    throw new \RuntimeException('boom');
                }
            }
        );
        $channel->method('wait_for_pending_acks_returns')->willReturn(true);
        $channel->method('is_open')->willReturn(true);
        $channel->method('close')->willReturn(null);

        $cm = $this->createMock(ConnectionManagerInterface::class);
        $cm->method('withChannel')->willReturnCallback(
            function ($fn) use ($channel) {
                return $fn($channel);
            }
        );
        $cm->method('reset'); // no explicit reset stub needed (void)

        $classifier = new class implements ExceptionClassifierInterface
        {
            public function isTransient(\Throwable $e): bool
            {
                return $e instanceof \RuntimeException;
            }
        };

        $pub = new Publisher(
            $cm,
            $mf,
            $classifier,
            null,
            [
                'base_delay' => 0.001,
                'max_delay' => 0.002,
                'jitter' => false,
            ]
        );
        $pub->publish($payload, retryTimes: 3);
        $this->assertSame(
            2,
            $attempts,
            'Should retry once on RuntimeException'
        );
    }

    public function testJitterProducesVariableSleep(): void
    {
        $payload = new class (['x' => 1]) implements ProducerPayloadInterface
        {
            public function __construct(private array $d)
            {
            }

            public function getConnectionName(): ?string
            {
                return 'default';
            }

            public function getExchangeName(): ?string
            {
                return 'ex';
            }

            public function getRoutingKey(): ?string
            {
                return 'rk';
            }

            public function jsonSerialize(): mixed
            {
                return $this->d;
            }
        };
        $mf = new MessageFactory(
            new class extends SystemClock {
            },
            'app',
            'testing'
        );

        $channel = $this->getMockBuilder(AMQPChannel::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'confirm_select',
                'basic_publish',
                'wait_for_pending_acks_returns',
                'is_open',
                'close',
            ])
            ->getMock();
        $channel->method('confirm_select')->willReturn(null);
        $calls = 0;
        $channel->method('basic_publish')->willReturnCallback(
            function () use (&$calls) {
                $calls++;
                if ($calls < 3) {
                    // force two failures -> two sleeps with jitter
                    throw new AMQPIOException('io');
                }
            }
        );
        $channel->method('wait_for_pending_acks_returns')->willReturn(true);
        $channel->method('is_open')->willReturn(true);
        $channel->method('close')->willReturn(null);

        $cm = $this->createMock(ConnectionManagerInterface::class);
        $cm->method('withChannel')->willReturnCallback(
            function ($fn) use ($channel) {
                return $fn($channel);
            }
        );
        $cm->method('reset'); // no explicit reset stub needed (void)

        $randSeq = [0.0, 1.0]; // extremes to show variability
        $pub = new Publisher(
            $cm,
            $mf,
            new class implements ExceptionClassifierInterface
            {
                public function isTransient(\Throwable $e): bool
                {
                    return true;
                }
            },
            null,
            [
                'base_delay' => 0.0005,
                'max_delay' => 0.001,
                'jitter' => true,
            ],
            function () use (&$randSeq) {
                return array_shift($randSeq) ?? 0.5;
            }
        );
        $start = microtime(true);
        $pub->publish($payload, retryTimes: 3);
        $elapsed = microtime(true) - $start;
        // Expect at least first sleep (~0.0005 * 0.85) + second (~0.001 * 1.15)
        // => total above ~0.0012 with margin.
        $this->assertGreaterThan(
            0.0008,
            $elapsed,
            'Elapsed should reflect two jittered sleeps'
        );
        $this->assertSame(3, $calls);
    }
}
