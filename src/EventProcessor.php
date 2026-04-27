<?php

namespace Uzapoint\EventBus;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;
use ReflectionClass;
use RuntimeException;

class EventProcessor
{
    public function __construct(
        protected EventRegistry $registry
    ) {}

    /**
     * Main entry point for processing events
     */
    public function process(AMQPMessage $message): void
    {
        $routingKey = $message->getRoutingKey();
        $payload = json_decode($message->getBody(), true);

        $trace = [
            'routing_key' => $routingKey,
            'delivery_tag' => $message->getDeliveryTag() ?? null,
        ];

        if (!is_array($payload) || !isset($payload['data'])) {
            Log::error('[EventBus] Invalid event envelope', $trace + [
                    'body' => $message->getBody(),
                ]);
            return;
        }

        $meta = $payload['meta'] ?? [];
        $data = $payload['data'];

        $eventId = $meta['id'] ?? null;
        $correlationId = $meta['correlation_id'] ?? $meta['id'] ?? null;

        $trace['correlation_id'] = $correlationId;

        /**
         * 1. Idempotency guard (must be atomic)
         */
        if ($this->isDuplicate($eventId)) {
            Log::warning('[EventBus] Duplicate event ignored', $trace + [
                    'event_id' => $eventId,
                ]);
            return;
        }

        /**
         * 2. Resolve handler (registry first, fallback to config)
         */
        $handler = $this->registry->getHandler($routingKey)
            ?? config("eventbus.handlers.$routingKey");

        if (!$handler) {
            Log::warning('[EventBus] No handler registered for event', $trace);
            return;
        }

        Log::info('[EventBus] Event received', $trace + [
                'handler' => $handler,
            ]);

        /**
         * 3. Execute handler safely
         */
        try {
            $this->dispatch($handler, $data);
        } catch (Throwable $e) {
            Log::error('[EventBus] Handler execution failed', $trace + [
                    'handler' => $handler,
                    'error' => $e->getMessage(),
                ]);

            /**
             * IMPORTANT:
             * Let consumer decide retry strategy (ack/nack).
             */
            throw $e;
        }
    }

    /**
     * Atomic idempotency check using SET NX EX
     */
    protected function isDuplicate(?string $id): bool
    {
        if (!$id) {
            return false;
        }

        $key = config('eventbus.idempotency.redis_prefix') . $id;
        $ttl = (int) config('eventbus.idempotency.ttl', 3600);

        try {
            $result = Redis::set($key, 1, 'EX', $ttl, 'NX');
            return $result === null;
        } catch (Throwable $e) {
            // Fail-open OR fail-safe decision:
            // we log but DO NOT block processing
            Log::error('[EventBus] Redis idempotency check failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Dispatch handler (supports Laravel jobs or service handlers)
     */
    protected function dispatch(string $handler, array $data): void
    {
        if (!class_exists($handler)) {
            throw new RuntimeException("Handler not found: {$handler}");
        }

        // Queueable job style
        if (method_exists($handler, 'dispatch')) {
            $params = $this->mapConstructor($handler, $data);
            $handler::dispatch(...$params);
            return;
        }

        // Service-style handler
        $instance = app($handler);

        if (!method_exists($instance, 'handle')) {
            throw new RuntimeException("Invalid handler: {$handler} (missing handle method)");
        }

        $instance->handle($data);
    }

    /**
     * Map constructor args dynamically
     */
    protected function mapConstructor(string $class, array $data): array
    {
        try {
            $reflection = new ReflectionClass($class);
            $constructor = $reflection->getConstructor();

            if (!$constructor) {
                return [];
            }

            return collect($constructor->getParameters())
                ->map(function ($param) use ($data) {
                    $name = $param->getName();

                    return match ($name) {
                        'authUserId' => $data['auth_user_id'] ?? null,
                        'payload'    => $data,
                        default      => $data[$name] ?? null,
                    };
                })
                ->values()
                ->toArray();

        } catch (Throwable $e) {
            Log::error('[EventBus] Constructor mapping failed', [
                'class' => $class,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}