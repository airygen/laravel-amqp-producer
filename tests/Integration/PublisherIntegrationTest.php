<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Tests\Integration;

use Airygen\RabbitMQ\Contracts\ProducerPayloadInterface as PayloadInterface;
use Airygen\RabbitMQ\Factories\ConnectionFactory;
use Airygen\RabbitMQ\Factories\MessageFactory;
use Airygen\RabbitMQ\Publisher;
use Airygen\RabbitMQ\Support\ConnectionManager;
use Airygen\RabbitMQ\Support\SystemClock;
use PHPUnit\Framework\TestCase;

/**
 * @group integration
 */
final class PublisherIntegrationTest extends TestCase
{
    private function makePayload(
        array $data,
        string $queue
    ): PayloadInterface {
        return new class ($data, $queue) implements PayloadInterface
        {
            public function __construct(private array $d, private string $queue)
            {
            }

            public function getConnectionName(): ?string
            {
                return 'default';
            }

            public function getExchangeName(): ?string
            {
                return null; // default exchange
            }

            public function getRoutingKey(): ?string
            {
                return $this->queue;
            }

            public function jsonSerialize(): mixed
            {
                return $this->d;
            }
        };
    }

    public function testPublishRoundTrip(): void
    {
        if (! getenv('INTEGRATION_TESTS')) {
            $this->markTestSkipped(
                'Set INTEGRATION_TESTS=1 to run integration tests.'
            );
        }

        $host = getenv('AMQP_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('AMQP_PORT') ?: 5672);
        $user = getenv('AMQP_USER') ?: 'guest';
        $pass = getenv('AMQP_PASSWORD') ?: 'guest';
        $vhost = getenv('AMQP_VHOST') ?: '/';

        $config = [
            'connections' => [
                'default' => [
                    'host' => $host,
                    'port' => $port,
                    'user' => $user,
                    'password' => $pass,
                    'vhost' => $vhost,
                    'options' => [
                        'reuse_channel' => true,
                        'max_channel_uses' => 10,
                        'heartbeat' => 30,
                    ],
                ],
            ],
            'retry' => [
                'base_delay' => 0.1,
                'max_delay' => 0.5,
                'jitter' => false,
            ],
        ];

        $factory = new ConnectionFactory();
        $manager = new ConnectionManager($factory, $config);
        $msgFactory = new MessageFactory(
            new SystemClock(),
            'integration-tests',
            'testing'
        );
        $publisher = new Publisher($manager, $msgFactory);

        $queue = 'amqp_producer_it_queue';

        // declare queue so publish with mandatory flag routes successfully
        $manager->withChannel(function ($ch) use ($queue) {
            // auto-delete queue
            $ch->queue_declare($queue, false, false, false, true);
        });

        $data = ['id' => uniqid('msg_', true)];
        $payload = $this->makePayload($data, $queue);

        // Act: publish (should not throw)
        $publisher->publish($payload, retryTimes: 2);

        // Assert: fetch one message from queue
        $got = null;
        $manager->withChannel(function ($ch) use ($queue, &$got) {
            $msg = $ch->basic_get($queue, true);
            if ($msg) {
                $got = json_decode($msg->getBody(), true);
            }
        });

        $this->assertNotNull(
            $got,
            'Expected a message fetched from queue'
        );
        $this->assertEquals($data['id'], $got['id'] ?? null);
    }
}
