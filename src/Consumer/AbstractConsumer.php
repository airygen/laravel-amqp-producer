<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Consumer;

use PhpAmqpLib\Message\AMQPMessage;

abstract class AbstractConsumer implements ConsumerInterface
{
    abstract protected function process(array $payload, array $headers): ProcessingResult;

    public function handle(AMQPMessage $message): ProcessingResult
    {
        try {
            $headers = $message->has('application_headers')
                ? $message->get('application_headers')->getNativeData()
                : [];
            $payload = json_decode($message->getBody(), true, 512, JSON_THROW_ON_ERROR);

            return $this->process($payload ?? [], $headers);
        } catch (\JsonException $e) {
            // payload 解析失敗：不可重試，丟到 DLX（由 broker 規則處理）
            return ProcessingResult::NACK_DROP;
        } catch (\InvalidArgumentException $e) {
            // 驗證失敗：不可重試
            return ProcessingResult::ACK;
        } catch (\Throwable $e) {
            // 未預期錯誤，交由上層根據設定決定 requeue 或 drop
            return ProcessingResult::NACK_REQUEUE;
        }
    }
}
