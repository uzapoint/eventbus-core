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
    |   ['name' => '', 'exchange' => '', 'routing_keys' => [], 'retry' => true],
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
        'enabled' => true,
        'ttl' => 86400000, // 24h in ms
        'max_retries' => 3,
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
    */
    'idempotency' => [
        'enabled' => true,
        'ttl' => 3600,
        'lock_ttl' => 120,
        'redis_prefix' => 'event:processed:',
    ],

    /*
    |--------------------------------------------------------------------------
    | Consumer Configuration
    |--------------------------------------------------------------------------
    | read_write_timeout must be > 2 x heartbeat.
    | liveness_dir: each consumer touches eventbus-<pid>.alive every loop; the
    | watchdog (ops/eventbus-watchdog.sh) kills consumers whose file goes stale.
    */
    'consumer' => [
        'prefetch_count' => 1,
        'heartbeat' => 60,
        'connection_timeout' => 3,
        'read_write_timeout' => 130,
        'liveness_dir' => env('EVENTBUS_LIVENESS_DIR'), // null => storage/framework/eventbus
    ],

    /*
    |--------------------------------------------------------------------------
    | Publishing Configuration
    |--------------------------------------------------------------------------
    | Bounds every publish so a stalled broker can never block a worker forever.
    */
    'publisher' => [
        'confirm_delivery' => true,
        'persistent_messages' => true,
        'connection_timeout' => 3,   // seconds to open the socket
        'read_write_timeout' => 15,  // seconds a single read/write may block
        'confirm_timeout' => 10,  // seconds to wait for the broker confirm
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring
    |--------------------------------------------------------------------------
    */
    'monitoring' => [
        'enabled' => true,
        'slow_event_threshold' => 5000, // ms
        'log_payload' => false,
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
