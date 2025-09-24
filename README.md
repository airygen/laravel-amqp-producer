# Laravel AMQP Producer

Laravel-oriented RabbitMQ publisher with:

* Publisher confirms
* Mandatory publish (detect unroutable messages)
* Exponential retry for transient AMQP errors
* Structured automatic headers: `request_id`, `source`, `env`, ISO8601 `datetime`
* Multi-connection support (choose connection per payload)

This library focuses on publishing only. For queue workers / consumers you can use: [vyuldashev/laravel-queue-rabbitmq](https://github.com/vyuldashev/laravel-queue-rabbitmq).

## Installation
```bash
composer require airygen/laravel-amqp-producer
```

## Publish Configuration
```bash
php artisan vendor:publish --provider="Airygen\\RabbitMQ\\RabbitMQServiceProvider" --tag=config
```
Generated file: `config/amqp.php`

```php
return [
    'retry' => [
        'base_delay' => 0.2,
        'max_delay' => 1.5,
        'jitter' => false, // set true to randomize backoff (helps avoid thundering herd)
    ],
    'connections' => [
        'default' => [
            'host' => env('AMQP_HOST', '127.0.0.1'),
            'port' => (int) env('AMQP_PORT', 5672),
            'user' => env('AMQP_USER', 'guest'),
            'password' => env('AMQP_PASSWORD', 'guest'),
            'vhost' => env('AMQP_VHOST', '/'),
            'options' => [
                'lazy' => true,
                'keepalive' => true,
                'heartbeat' => 60,
                // Reuse a single channel per connection (improves throughput). Disable if you need short-lived channels per publish.
                'reuse_channel' => true,
                // Recreate the reused channel after N publishes to avoid very long-lived delivery tag growth.
                'max_channel_uses' => 5000,
            ],
        ],
        // Add more named connections if needed
        // 'analytics' => [ ... ],
    ],
];
```

## Defining a Payload
Extend `ProducerPayload` and (optionally) override connection / exchange / routing key.

```php
use Airygen\RabbitMQ\ProducerPayload;

final class MemberCreatedPayload extends ProducerPayload
{
    protected string $connectionName = 'default';              // optional (defaults to 'default')
    protected ?string $exchangeName = 'ex.members';            // required if you publish to a non-empty exchange
    protected ?string $routingKey = 'member.created';          // required for direct/topic exchanges
}
```

## Basic Publish
```php
use Airygen\RabbitMQ\Publisher;

$publisher = app(Publisher::class);
$publisher->publish(new MemberCreatedPayload(['id' => 123]));
```

## Custom Headers
```php
$publisher->publish(
    new MemberCreatedPayload(['id' => 123]),
    header: ['foo' => 'bar']
);
```

## Batch Publish
```php
$payloads = [
    new MemberCreatedPayload(['id' => 1]),
    new MemberCreatedPayload(['id' => 2]),
];

$publisher->batchPublish($payloads);
```

## Retry Strategy
The publisher retries transient AMQP IO / protocol errors (IO / protocol channel exceptions) with exponential backoff:

* Initial delay `base_delay` (~200ms), doubled each attempt, capped at `max_delay` (~1500ms)
* Default attempts: 3

Custom rule:
```php
$publisher->publish(
    new MemberCreatedPayload(['id' => 1]),
    retryTimes: 5,
    when: function (Throwable $e): bool {
        return $e instanceof PhpAmqpLib\Exception\AMQPIOException
            || str_contains($e->getMessage(), 'timeout');
    }
);
```

Disable retry:
Enable jitter:
```php
config(['amqp.retry.jitter' => true]);
$publisher->publish($payload);
```
Jitter multiplies each delay by a random factor ~0.85 - 1.15.
```php
$publisher->publish(
    new MemberCreatedPayload(['id' => 1]),
    retryTimes: 1,
    when: fn() => false
);
```

## Multi-Connection Example
```php
final class AnalyticsEventPayload extends ProducerPayload
{
    protected string $connectionName = 'analytics';
    protected ?string $exchangeName = 'ex.analytics';
    protected ?string $routingKey = 'event.ingest';
}

$publisher->publish(new AnalyticsEventPayload(['type' => 'login']));
```

## Automatic Headers Added
`MessageFactory` injects:
* `request_id` (existing `X-Request-Id` header or a new UUID)
* `source` (Laravel app name)
* `env` (current environment)
* `datetime` (ISO8601)

You can still provide additional custom headers; your keys override defaults if duplicated.

Header precedence: custom headers provided in `publish()` / `batchPublish()` override automatically injected keys when the same key exists.

## Metrics & Stats
The package ships with an in-memory static counter registry `Stats` intended for lightweight instrumentation or exporting into your own monitoring system.

Global counters:
* `publish_attempts`
* `publish_retries`
* `publish_failures`
* `connection_resets`

Per‑connection counters (nested under `per_connection[connection_name]`):
* `publish_attempts`
* `publish_retries`
* `publish_failures`
* `connection_resets`

Example snapshot:
```php
use Airygen\RabbitMQ\Support\Stats;
$snapshot = Stats::snapshot();
// [ 'publish_attempts' => 10, 'per_connection' => ['default' => ['publish_attempts' => 7]] ]
```
You can reset counters (e.g. at the start of a test or scheduled export) with:
```php
Stats::reset();
```
For production telemetry, consider periodically reading the snapshot and pushing to Prometheus / OpenTelemetry.*

> Note: These counters are process‑local (not shared across workers). If you run Octane/Swoole multi-worker, aggregate externally.

## Performance: Channel Reuse
Opening a channel for every publish adds latency. By default, if `options.reuse_channel` is true, the connection manager will:

1. Create one channel the first time you publish on a connection.
2. Reuse it while it stays open and usage < `max_channel_uses`.
3. Rotate (close & recreate) after the threshold or if it becomes stale/closed.
4. `reset()` explicitly disposes both the connection and any cached channel.

Reasons to keep reuse enabled (recommended):
* Lower per-message overhead
* Fewer syscalls and protocol frames

Reasons to disable (`reuse_channel` => false):
* You frequently declare/delete exclusive/temporary queues mid-flow
* You rely on channel-level QoS changes per publish
* You are diagnosing channel state issues

Tuning:
* Increase `max_channel_uses` for very high throughput producers (e.g. 50k+ messages / minute)
* Decrease if you want more frequent rotation for memory / delivery-tag reset hygiene

Fallback: If reuse is disabled, behavior reverts to open→publish→close each call as before.

## Octane / Swoole / RoadRunner
## Health Check Command
Run a simple connectivity probe:
```bash
php artisan rabbitmq:ping            # test all configured connections
php artisan rabbitmq:ping secondary  # test a specific connection
```
Exit code is non‑zero on failure (suitable for container readiness / liveness probes).
Long-lived worker environments reuse PHP processes, so you must ensure stale connections/channels don't leak across deploys or forks.

Built-in safeguards:
* On worker start/stop (Octane events) the connection manager `reset()` is invoked (if Octane is installed).
* Internal PID detection: if a forked worker inherits the manager, the first call to `get()` notices PID drift and resets lazily.

Recommended practices:
1. Avoid holding a `Publisher` instance in static singletons you construct before workers fork.
2. Call `app(ProducerInterface::class)` per request/job (container will reuse safe singleton manager underneath).
3. If you rotate workers periodically, no extra action is needed—the hook already clears state.
4. For Swoole without Octane events, you can manually schedule: `ConnectionManager::reset()` during your custom lifecycle hooks.

Optional manual reset example:
```php
// e.g. in a scheduled task or health hook
app(\Airygen\RabbitMQ\Support\ConnectionManager::class)->reset();
```

## TLS / SSL (Optional)
If you need TLS encryption, enable and configure the SSL related options inside `config/amqp.php`:
```php
    'connections' => [
        'default' => [
            // ... host, port, user, password, vhost
            'options' => [
                'reuse_channel' => true,
                'max_channel_uses' => 5000,
                'ssl' => true,
                'cafile' => base_path('certs/ca.pem'),
                'local_cert' => base_path('certs/client.pem'),
                'local_pk' => base_path('certs/client.key'),
                'verify_peer' => true,
                // 'passphrase' => env('AMQP_CERT_PASSPHRASE'),
            ],
        ],
    ],
```
The factory will build a stream context when `ssl` is truthy and any of the certificate fields are present. If `verify_peer` is enabled, ensure `cafile` is supplied.

## Roadmap
* Pluggable metrics exporter interface
* Circuit breaker / total backoff budget
* Async publisher confirm pipeline
* Mandatory publish return callbacks (unroutable detection)
* Dead letter / delayed publish helpers

## Development

### Dockerized Workflow
All commands are wrapped to run inside the `php` service defined in `docker-compose.yml`.

Startup & install:
```bash
docker compose up -d rabbitmq
docker compose build php
docker compose run --rm php composer install
```

Using Makefile targets:
```bash
make unit          # run unit tests
make integration   # run integration tests (requires rabbitmq service up)
make test          # unit + integration
make coverage      # generates coverage/html & coverage/clover.xml
make lint          # code style check
make analyse       # phpstan static analysis
make fix           # auto-fix style
```

Manual (host) without Docker wrapper:
```bash
php -d xdebug.mode=off vendor/bin/phpunit --testsuite Unit
INTEGRATION_TESTS=1 php -d xdebug.mode=off vendor/bin/phpunit --testsuite Integration
```

Management UI: http://localhost:15672 (guest / guest)

If you prefer to run host-native (without docker) use the `host:*` scripts or call PHPUnit directly.

### Prometheus Metrics (Skeleton)
The package keeps lightweight in-memory counters (not persisted). A minimal Prometheus text exporter is provided.

Artisan command:
```bash
php artisan rabbitmq:metrics            # full HELP/TYPE + samples
php artisan rabbitmq:metrics --raw      # only metric lines
```

Example output:
```
# HELP rabbitmq_publish_attempts_total Total publish attempts (before confirm).
# TYPE rabbitmq_publish_attempts_total counter
rabbitmq_publish_attempts_total 42
... (other metrics)
```

Per-connection metrics are emitted with a `connection` label, e.g.:
```
rabbitmq_connection_publish_attempts_total{connection="primary"} 10
```

You can bind your own implementation of `Airygen\\RabbitMQ\\Contracts\\MetricsExporterInterface` if you need richer aggregation or to integrate with an existing metrics system.

## License
MIT