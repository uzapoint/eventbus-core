# Uzapoint EventBus Core

Reusable Event Bus infrastructure for Laravel microservices, built on RabbitMQ with Redis-backed idempotency.

Repository: https://github.com/uzapoint/eventbus-core

---

## Features

- Standardized event envelope (meta + data)
- RabbitMQ publisher with confirmations and bounded timeouts
- Config-driven consumer that exits on any error, so the supervisor restarts it
- Retry with backoff, then parking in `<queue>.dlq`
- Two-phase idempotency (Redis)
- Liveness watchdog for hung consumers
- `eventbus:doctor` health audit
- Laravel auto-discovery

---

## Installation

```bash
composer require uzapoint/eventbus-core
```

Requirements: PHP 8.4+, Laravel 11/12, RabbitMQ, Redis (phpredis or predis), `pcntl`.

---

## Configuration

A service only defines what is specific to it. All other settings come from the package defaults and are merged key by key, so override only what you need (for example `'consumer' => ['prefetch_count' => 5]`).

```php
// config/eventbus.php
return [

    'exchanges' => [
        'auth.events',
        'business.events',
    ],

    'queues' => [
        [
            'name' => 'auth_ms.business_events',
            'exchange' => 'business.events',
            'routing_keys' => [
                'tenant.registration.initiated',
            ],
        ],
    ],

    'handlers' => [
        'tenant.registration.initiated' => ProcessTenantCreation::class,
    ],

];
```

To see every available option: `php artisan vendor:publish --tag=eventbus-config`.

Do not bind your own `EventProcessor`; retries and idempotency are handled by the package.

---

## Publishing Events

```php
use Uzapoint\EventBus\RabbitMQService;

app(RabbitMQService::class)->publish(
    exchange: 'business.events',
    routingKey: 'tenant.registration.initiated',
    data: [
        'auth_user_id' => 12,
        'email' => 'user@example.com',
    ],
    headers: ['idempotency_key' => $key], // optional: used as the event id
);
```

Envelope:

```json
{
  "meta": {
    "id": "...",
    "type": "tenant.registration.initiated",
    "source": "auth-service",
    "timestamp": "..."
  },
  "data": { ... }
}
```

Publishing connects with `publisher.connection_timeout`, `read_write_timeout` and `confirm_timeout`, and drops its connection after any failure. A failed publish throws.

---

## Consuming Events

```bash
php artisan eventbus:consume
php artisan eventbus:consume --queue=auth_ms.business_events
```

Run it under supervisor with `autorestart=true`. Include the shared programs from the package (two consumers plus the watchdog) from the service's `supervisord.conf`:

```ini
[include]
files = /var/www/html/%(ENV_SERVICE_NAME)s/vendor/uzapoint/eventbus-core/ops/supervisor-eventbus.conf
```

It needs `SERVICE_NAME` in the container environment and a writable `storage/`.

### Failures

A failing handler is retried `dead_letter.max_retries` times (2, 4, 6 s backoff), then the message is parked in `<queue>.dlq`. Alert on any message in a `.dlq` queue.

Handlers that are queued jobs (`ShouldQueue`) only enqueue work. Their later failures go to Horizon's `failed_jobs`, not back to RabbitMQ. Use a plain handler class for critical flows.

---

## Idempotency

```php
'idempotency' => [
    'enabled' => true,
    'ttl' => 3600,      // how long a successfully processed event is remembered
    'lock_ttl' => 120,  // processing lock, released if the handler fails
    'redis_prefix' => 'event:processed:',
],
```

Namespace the prefix per service (for example `event:processed:sales:`) when several services consume the same message.

---

## Watchdog

Each consumer touches `eventbus-<pid>.alive` in `consumer.liveness_dir` (default `storage/framework/eventbus`) at least every ~5 s. `ops/eventbus-watchdog.sh` kills a consumer whose file is older than `STALE_AFTER` (120 s) and supervisor starts a new one.

---

## Service checklist

- `timeout` and `read_timeout` set on every Redis connection (`config/database.php`).
- `queue.connections.redis.retry_after` greater than the Horizon worker `timeout`.
- Job classes assign `$this->afterCommit = true;` in the constructor and never redeclare the property.

Check all of it, plus handlers, topology, Redis and RabbitMQ:

```bash
php artisan eventbus:doctor
php artisan eventbus:doctor --skip-broker
```

---

## Monitoring

```php
'monitoring' => [
    'enabled' => true,
    'slow_event_threshold' => 5000, // ms
],
```

---

## License

MIT

## Author

Sammy Orondo
Lead Developer — Uzapoint
[https://github.com/sammy-boy](https://github.com/sammy-boy)
