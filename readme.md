# 

Repository:
[https://github.com/uzapoint/eventbus-core](https://github.com/uzapoint/eventbus-core)

---

````markdown
# Uzapoint EventBus Core

Reusable, opinionated Event Bus infrastructure for Laravel microservices.

Built for scalable, event-driven architectures using RabbitMQ and Redis-backed idempotency.

---

## Features

- Standardized event envelope (meta + data)
- Automatic idempotency protection (Redis)
- RabbitMQ publisher with confirmations
- Config-driven consumer
- Dead Letter Exchange support
- Slow-event monitoring
- Laravel auto-discovery support
- Production-ready defaults with flexibility

---

## Installation

```bash
composer require uzapoint/eventbus-core
````

---

## Configuration

Publish configuration:

```bash
php artisan vendor:publish --tag=eventbus-config
```

Example:

```php
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

---

## Publishing Events

```php
use Uzapoint\EventBus\RabbitMQService;

$eventBus->publish(
    exchange: 'business.events',
    routingKey: 'tenant.registration.initiated',
    data: [
        'auth_user_id' => 12,
        'email' => 'user@example.com',
    ]
);
```

Envelope automatically becomes:

```json
{
  "meta": {
    "id": "...",
    "type": "tenant.registration.initiated",
    "source": "auth-service",
    "timestamp": "...",
    "correlation_id": "..."
  },
  "data": { ... }
}
```

---

## Consuming Events

Run:

```bash
php artisan eventbus:consume
```

Options:

```bash
php artisan eventbus:consume --queue=auth_ms.business_events
php artisan eventbus:consume --exchange=business.events
```

---

## Idempotency

Prevents duplicate event execution.

Configured via:

```php
'idempotency' => [
    'enabled' => true,
    'ttl' => 3600,
    'redis_prefix' => 'event:processed:',
],
```

---

## Monitoring

Detect slow events:

```php
'monitoring' => [
    'enabled' => true,
    'slow_event_threshold' => 5000, // ms
],
```

---

## Architecture Philosophy

Opinionated but flexible.

This package enforces:

* Standardized event structure
* Config-driven topology
* Explicit handler mapping

But allows:

* Custom queue naming
* Custom exchanges
* Retry behavior overrides
* Dead-letter configuration
* Performance tuning

---

## Requirements

* PHP 8.4+
* Laravel 11 / 12+
* RabbitMQ
* Redis

---

## License

MIT

---

## Author

Sammy Orondo
Lead Developer — Uzapoint
[https://github.com/sammy-boy](https://github.com/sammy-boy)