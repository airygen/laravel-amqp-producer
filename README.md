# Airygen RabbitMQ (Full)

Laravel-oriented RabbitMQ toolkit with **publisher confirms**, **mandatory publish**, **retriable connection handling**, and a **simple consumer API**.

## Features
- ConnectionManager (reusable connection; auto-recreate; reset on failure)
- Publisher with confirms + `mandatory=true` to detect unroutable
- Exponential retry with filter for transient AMQP exceptions
- MessageFactory injects `request_id`, `source`, `env`, ISO datetime
- Consumer base class with clear **ack/nack** decisions
- PHPStan lvl 8, Pint style, PHPUnit tests, GitHub Actions CI

## Install
```bash
composer require airygen/rabbitmq-full
php artisan vendor:publish --tag=config --provider="Airygen\RabbitMQ\RabbitMQServiceProvider"
```

## Config (`config/amqp.php`)
- `base_connection`: host/port/user/password/vhost/options
- `channels.{name}`: `exchange_name`, `routing_key`
- `consumer.prefetch`: QoS prefetch count
- `consumer.unexpected`: default for unexpected exceptions (`drop` or `requeue`)

## Producer Usage
```php
use Airygen\RabbitMQ\Contracts\ProducerInterface;
use Airygen\RabbitMQ\Contracts\ProducerPayload;

final class MemberCreatedPayload implements ProducerPayload {
    public function __construct(private array $data)
    {
    }

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
        return $this->data;
    }
}

// app code
$producer->publish(new MemberCreatedPayload(['id' => 1]));
```

## Consumer Usage
```php
use Airygen\RabbitMQ\Consumer\AbstractConsumer;
use Airygen\RabbitMQ\Consumer\ProcessingResult;

final class SendWelcomeEmailConsumer extends AbstractConsumer
{
    protected function process(array $payload, array $headers): ProcessingResult
    {
        // validate
        if (!isset($payload['email'])) {
            // validation error -> ACK (drop)
            return ProcessingResult::ACK;
        }

        // send email...
        return ProcessingResult::ACK;
    }
}
```

### Error handling matrix (recommendation)

| Situation                          | Result                      | Notes                         |
|-----------------------------------|-----------------------------|-------------------------------|
| JSON decode failure               | `NACK_DROP`                 | Treat as poison, DLX handles  |
| Validation / domain invariant     | `ACK`                       | Logged & drop                 |
| External/transient (network, ...) | `NACK_REQUEUE`              | Allow broker to retry         |
| Unroutable when publishing        | Confirm NACK/timeout -> retry; else fail | `mandatory=true` + confirms |

> Configure DLX (dead-letter exchange) and per-queue `x-dead-letter-exchange`/`x-dead-letter-routing-key` on the broker or via infra-as-code. Library purposely keeps broker topology outside of app code.

## Development
```bash
composer install
composer analyse
composer lint
composer test
```

## CI
See `.github/workflows/ci.yml` for a minimal pipeline.