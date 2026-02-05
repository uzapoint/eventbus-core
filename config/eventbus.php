<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Exchanges
    |--------------------------------------------------------------------------
    | Define topic exchanges this service interacts with.
    | Leave empty and define inside microservice if needed.
    */
    'exchanges' => [],


    /*
    |--------------------------------------------------------------------------
    | Queues
    |--------------------------------------------------------------------------
    | Queue topology definition.
    | Structure:
    | [
    |   [
    |       'name' => '',
    |       'exchange' => '',
    |       'routing_keys' => [],
    |       'retry' => true,
    |   ],
    | ]
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
    */
    'dead_letter' => [
        'enabled'      => true,
        'ttl'          => 86400000, // 24h in ms
        'max_retries'  => 3,
        'exchange_prefix' => 'dlx.',
    ],


    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    */
    'idempotency' => [
        'enabled'      => true,
        'ttl'          => 3600,
        'redis_prefix' => 'event:processed:',
    ],


    /*
    |--------------------------------------------------------------------------
    | Consumer Configuration
    |--------------------------------------------------------------------------
    */
    'consumer' => [
        'prefetch_count' => 1,
        'heartbeat'      => 60,
        'connection_timeout' => 3,
        'read_write_timeout' => 130,
        'retry_on_failure' => false, // allow override
    ],


    /*
    |--------------------------------------------------------------------------
    | Publishing Configuration
    |--------------------------------------------------------------------------
    */
    'publisher' => [
        'confirm_delivery' => true,
        'persistent_messages' => true,
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
    | Correlation ID propagation support
    */
    'tracing' => [
        'enabled' => true,
        'header'  => 'X-Correlation-ID',
        'generate_if_missing' => true,
    ],

];