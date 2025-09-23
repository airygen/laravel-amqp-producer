<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Tests;

use Airygen\RabbitMQ\Consumer\AbstractConsumer;
use Airygen\RabbitMQ\Consumer\ProcessingResult;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\TestCase;

final class ConsumerTest extends TestCase
{
    public function test_consumer_ack_on_valid_payload(): void
    {
        $c = new class extends AbstractConsumer
        {
            protected function process(array $payload, array $headers): ProcessingResult
            {
                return ProcessingResult::ACK;
            }
        };
        $msg = new AMQPMessage(json_encode(['ok' => true]));
        $this->assertSame(ProcessingResult::ACK, $c->handle($msg));
    }

    public function test_consumer_drop_on_json_error(): void
    {
        $c = new class extends AbstractConsumer
        {
            protected function process(array $payload, array $headers): ProcessingResult
            {
                return ProcessingResult::ACK;
            }
        };
        $msg = new AMQPMessage('{"broken": '); // invalid
        $this->assertSame(ProcessingResult::NACK_DROP, $c->handle($msg));
    }

    public function test_consumer_requeue_on_unexpected(): void
    {
        $c = new class extends AbstractConsumer
        {
            protected function process(array $payload, array $headers): ProcessingResult
            {
                throw new \RuntimeException('boom');
            }
        };
        $msg = new AMQPMessage(json_encode(['ok' => true]));
        $this->assertSame(ProcessingResult::NACK_REQUEUE, $c->handle($msg));
    }
}
