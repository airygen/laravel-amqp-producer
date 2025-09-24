<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Tests;

use Airygen\RabbitMQ\Contracts\ConnectionManagerInterface;
use Airygen\RabbitMQ\Contracts\ProducerPayloadInterface as PayloadInterface;
use Airygen\RabbitMQ\Factories\MessageFactory;
use Airygen\RabbitMQ\Publisher;
use Airygen\RabbitMQ\Support\Stats;
use Airygen\RabbitMQ\Support\SystemClock;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPIOException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class StatsAndCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Stats::reset();
    }

    private function makePayload(
        array $data,
        string $conn = 'default'
    ): PayloadInterface {
        return new class ($data, $conn) implements PayloadInterface
        {
            private array $d;

            private string $c;

            public function __construct(array $d, string $c)
            {
                $this->d = $d;
                $this->c = $c;
            }

            public function getConnectionName(): ?string
            {
                return $this->c;
            }

            public function getExchangeName(): ?string
            {
                return 'ex.stats';
            }

            public function getRoutingKey(): ?string
            {
                return 'rk.stats';
            }

            public function jsonSerialize(): mixed
            {
                return $this->d;
            }
        };
    }

    public function testStatsIncrementOnSuccessAndRetryAndFailure(): void
    {
        $mf = new MessageFactory(
            new class extends SystemClock {
            },
            'app',
            'testing'
        );

        $calls = 0;
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
        $channel->method('wait_for_pending_acks_returns')->willReturn(true);
        $channel->method('is_open')->willReturn(true);
        $channel->method('close')->willReturn(null);
        $channel->method('basic_publish')->willReturnCallback(
            function () use (&$calls) {
                $calls++;
                if ($calls < 2) {
                    throw new AMQPIOException('temp');
                }
            }
        );

        $cm = $this->createMock(ConnectionManagerInterface::class);
        $cm->method('withChannel')->willReturnCallback(
            function ($fn) use ($channel) {
                return $fn($channel);
            }
        );
        $cm->method('reset')->willReturnCallback(function () {
        });

        $pub = new Publisher(
            $cm,
            $mf,
            null,
            null,
            ['base_delay' => 0.0002, 'max_delay' => 0.0003, 'jitter' => false]
        );
        $payload = $this->makePayload(['ok' => 1]);
        $pub->publish(
            $payload,
            retryTimes: 3,
            when: fn (\Throwable $e) => $e instanceof AMQPIOException
        );

        $snap = Stats::snapshot();
        $this->assertSame(2, $snap['publish_attempts']);
        $this->assertSame(1, $snap['publish_retries']);
        $this->assertSame(0, $snap['publish_failures']);
        $this->assertSame(1, $snap['connection_resets']);
        $this->assertArrayHasKey('per_connection', $snap);
        $perConnection = $snap['per_connection']['default'] ?? [];
        $this->assertSame(2, $perConnection['publish_attempts'] ?? null);

        $channel2 = $this->getMockBuilder(AMQPChannel::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'confirm_select',
                'basic_publish',
                'wait_for_pending_acks_returns',
                'is_open',
                'close',
            ])
            ->getMock();
        $channel2->method('confirm_select')->willReturn(null);
        $channel2->method('wait_for_pending_acks_returns')->willReturn(true);
        $channel2->method('is_open')->willReturn(true);
        $channel2->method('close')->willReturn(null);
        $channel2->method('basic_publish')->willReturnCallback(
            function () {
                throw new \RuntimeException('fatal');
            }
        );

        $cm2 = $this->createMock(ConnectionManagerInterface::class);
        $cm2->method('withChannel')->willReturnCallback(
            function ($fn) use ($channel2) {
                return $fn($channel2);
            }
        );
        $cm2->method('reset')->willReturnCallback(function () {
        });
        $pub2 = new Publisher(
            $cm2,
            $mf,
            null,
            null,
            ['base_delay' => 0.0001, 'max_delay' => 0.0002]
        );
        $this->expectException(\RuntimeException::class);
        try {
            $pub2->publish(
                $this->makePayload(['x' => 2]),
                retryTimes: 2,
                when: fn () => false
            );
        } finally {
            $snap2 = Stats::snapshot();
            $this->assertSame(3, $snap2['publish_attempts']);
            $this->assertSame(1, $snap2['publish_failures']);
        }
    }

    public function testLoggerReceivesRetryMessages(): void
    {
        $mf = new MessageFactory(new class extends SystemClock {
        }, 'app', 'testing');
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
        $channel->method('wait_for_pending_acks_returns')->willReturn(true);
        $channel->method('is_open')->willReturn(true);
        $channel->method('close')->willReturn(null);
        $calls = 0;
        $channel->method('basic_publish')->willReturnCallback(
            function () use (&$calls) {
                $calls++;
                if ($calls < 2) {
                    throw new AMQPIOException('retry');
                }
            }
        );

        $cm = $this->createMock(ConnectionManagerInterface::class);
        $cm->method('withChannel')->willReturnCallback(
            function ($fn) use ($channel) {
                return $fn($channel);
            }
        );
        $cm->method('reset')->willReturnCallback(function () {
        });

        $logs = [];
        $logger = new class ($logs) implements LoggerInterface
        {
            private array $ref;

            public function __construct(array &$ref)
            {
                $this->ref = &$ref;
            }

            public function emergency($message, array $context = []): void
            {
                $this->ref[] = ['emergency', $message, $context];
            }

            public function alert($message, array $context = []): void
            {
                $this->ref[] = ['alert', $message, $context];
            }

            public function critical($message, array $context = []): void
            {
                $this->ref[] = ['critical', $message, $context];
            }

            public function error($message, array $context = []): void
            {
                $this->ref[] = ['error', $message, $context];
            }

            public function warning($message, array $context = []): void
            {
                $this->ref[] = ['warning', $message, $context];
            }

            public function notice($message, array $context = []): void
            {
                $this->ref[] = ['notice', $message, $context];
            }

            public function info($message, array $context = []): void
            {
                $this->ref[] = ['info', $message, $context];
            }

            public function debug($message, array $context = []): void
            {
                $this->ref[] = ['debug', $message, $context];
            }

            public function log($level, $message, array $context = []): void
            {
                $this->ref[] = [$level, $message, $context];
            }
        };

        $pub = new Publisher(
            $cm,
            $mf,
            null,
            $logger,
            ['base_delay' => 0.0001, 'max_delay' => 0.0002]
        );
        $pub->publish(
            $this->makePayload(['x' => 1]),
            retryTimes: 2,
            when: fn (\Throwable $e) => $e instanceof AMQPIOException
        );
        $retryLogs = array_filter(
            $logs,
            fn ($entry) => $entry[0] === 'warning'
                && str_contains($entry[1], 'transient publish failure')
        );
        $this->assertNotEmpty($retryLogs);
    }

    public function testPidDriftTriggersReset(): void
    {
        $mf = new MessageFactory(
            new class extends SystemClock {
            },
            'app',
            'testing'
        );
        $payload = $this->makePayload(['z' => 1]);

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
        $channel->method('wait_for_pending_acks_returns')->willReturn(true);
        $channel->method('is_open')->willReturn(true);
        $channel->method('close')->willReturn(null);
        $channel->method('basic_publish')->willReturn(null);

        $cm = $this->createMock(ConnectionManagerInterface::class);
        $resets = 0;
        $channels = 0;
        $cm->method('withChannel')->willReturnCallback(
            function ($fn) use ($channel, &$channels) {
                $channels++;

                return $fn($channel);
            }
        );
        $cm->method('reset')->willReturnCallback(function () use (&$resets) {
            $resets++;
        });

        $pub = new Publisher($cm, $mf);
        $pub->publish($payload);
        $cm->reset();
        $pub->publish($payload);
        $this->assertSame(
            1,
            $resets,
            'Manual reset should be counted once '
            . '(publish path uses withChannel only)'
        );
    }
}
