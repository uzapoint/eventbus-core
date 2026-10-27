<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Exchanges
    |--------------------------------------------------------------------------
    | Topic exchanges this service interacts with. Define per microservice.
    */
    'exchanges' => [],

    /*
    |--------------------------------------------------------------------------
    | Queues
    |--------------------------------------------------------------------------
    | [
    |   ['name' => '', 'exchange' => '', 'routing_keys' => []],
    | ]
    | NOTE: changing x-* arguments of an existing queue causes PRECONDITION_FAILED.
    */
    'queues' => [],

    /*
    |--------------------------------------------------------------------------
    | Event Handlers
    |--------------------------------------------------------------------------
    | Map routing keys to handlers.
    */
    'handlers' => [],

    /*
    |--------------------------------------------------------------------------
    | Dead Letter Strategy
    |--------------------------------------------------------------------------
    | After max_retries a message is parked in "<queue>.dlq" (never silently acked away).
    */
    'dead_letter' => [
        'enabled' => env('EVENTBUS_DLX_ENABLED', true),
        'ttl' => (int) env('EVENTBUS_DLX_TTL', 86400000), // 24h in ms
        'max_retries' => (int) env('EVENTBUS_MAX_RETRIES', 3),
        'exchange_prefix' => 'dlx.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    | Two phases: a short "processing" lock (lock_ttl) is taken before the handler runs and is
    | released if it fails; the event is marked done for the full ttl only AFTER it succeeded.
    | lock_ttl must be longer than the slowest handler, and short enough that a replay after a
    | crashed worker is not ignored for long.
    | Give every service its own redis_prefix when several services consume the same message.
    */
    'idempotency' => [
        'enabled' => env('EVENTBUS_IDEMPOTENCY_ENABLED', true),
        'ttl' => (int) env('EVENTBUS_IDEMPOTENCY_TTL', 3600),
        'lock_ttl' => (int) env('EVENTBUS_IDEMPOTENCY_LOCK_TTL', 120),
        'redis_prefix' => env('EVENTBUS_IDEMPOTENCY_PREFIX', 'event:processed:'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Consumer Configuration
    |--------------------------------------------------------------------------
    | read_write_timeout must be > 2 x heartbeat.
    | liveness_dir: each consumer touches eventbus-<pid>.alive every loop; the watchdog
    | (ops/eventbus-watchdog.sh) kills consumers whose file goes stale. null => storage/framework/eventbus.
    */
    'consumer' => [
        'prefetch_count' => (int) env('EVENTBUS_PREFETCH_COUNT', 1),
        'heartbeat' => (int) env('EVENTBUS_HEARTBEAT', 60),
        'connection_timeout' => (float) env('EVENTBUS_CONNECTION_TIMEOUT', 3),
        'read_write_timeout' => (float) env('EVENTBUS_READ_WRITE_TIMEOUT', 130),
        'liveness_dir' => env('EVENTBUS_LIVENESS_DIR'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Publishing Configuration
    |--------------------------------------------------------------------------
    | Bounds every publish so a stalled broker can never block a worker forever.
    */
    'publisher' => [
        'connection_timeout' => (float) env('EVENTBUS_PUBLISHER_CONNECTION_TIMEOUT', 3),  // seconds to open the socket
        'read_write_timeout' => (float) env('EVENTBUS_PUBLISHER_READ_WRITE_TIMEOUT', 15), // seconds a single read/write may block
        'confirm_timeout' => (float) env('EVENTBUS_PUBLISHER_CONFIRM_TIMEOUT', 10),    // seconds to wait for the broker confirm
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring
    |--------------------------------------------------------------------------
    */
    'monitoring' => [
        'enabled' => env('EVENTBUS_MONITORING_ENABLED', true),
        'slow_event_threshold' => (int) env('EVENTBUS_SLOW_EVENT_THRESHOLD', 5000), // ms
    ],

    /*
    |--------------------------------------------------------------------------
    | Tracing
    |--------------------------------------------------------------------------
    */
    'tracing' => [
        'enabled' => true,
        'header' => 'X-Correlation-ID',
        'generate_if_missing' => true,
    ],

];
