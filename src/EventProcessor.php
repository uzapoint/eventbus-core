<?php

namespace Uzapoint\EventBus;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use OutOfBoundsException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;
use ReflectionClass;
use RuntimeException;

class EventProcessor
{
    protected const ACQUIRED = 'acquired';
    protected const DONE = 'done';
    protected const PROCESSING = 'processing';
    protected const STATE_PROCESSING = 'processing';

    public function __construct(
        protected EventRegistry $registry
    ) {
    }

    public function process(AMQPMessage $message): void
    {
        $routingKey = $this->originalRoutingKey($message);
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
         * 1. Resolve handler (registry first, fallback to config)
         */
        $handler = $this->registry->getHandler($routingKey)
            ?? config("eventbus.handlers.$routingKey");

        if (!$handler) {
            Log::warning('[EventBus] No handler registered for event', $trace);
            return;
        }

        $state = $this->acquire($eventId);

        if ($state !== self::ACQUIRED) {
            Log::warning('[EventBus] Duplicate event ignored', $trace + [
                'event_id' => $eventId,
                'state' => $state,
            ]);
            return;
        }

        Log::info('[EventBus] Event received', $trace + [
            'handler' => $handler,
        ]);

        try {
            $this->dispatch($handler, $data);
        } catch (Throwable $e) {
            Log::error('[EventBus] Handler execution failed', $trace + [
                'handler' => $handler,
                'error' => $e->getMessage(),
            ]);

            $this->release($eventId);

            throw $e;
        }

        $this->markProcessed($eventId);
    }

    protected function originalRoutingKey(AMQPMessage $message): string
    {
        try {
            $table = $message->get('application_headers');
        } catch (OutOfBoundsException) {
            return $message->getRoutingKey();
        }

        if ($table instanceof AMQPTable) {
            $original = $table->getNativeData()['x-original-routing-key'] ?? null;
            if (is_string($original) && $original !== '') {
                return $original;
            }
        }

        return $message->getRoutingKey();
    }

    protected function idempotencyKey(string $id): string
    {
        return config('eventbus.idempotency.redis_prefix', 'event:processed:') . $id;
    }

    protected function acquire(?string $id): string
    {
        if (!$id || !config('eventbus.idempotency.enabled', true)) {
            return self::ACQUIRED;
        }

        $key = $this->idempotencyKey($id);
        $lockTtl = (int) config('eventbus.idempotency.lock_ttl', 120);

        try {
            if (Redis::set($key, self::STATE_PROCESSING, 'EX', $lockTtl, 'NX')) {
                return self::ACQUIRED;
            }

            return (string) Redis::get($key) === self::STATE_PROCESSING ? self::PROCESSING : self::DONE;
        } catch (Throwable $e) {
            // Fail open: better to risk a duplicate than to lose an event.
            Log::error('[EventBus] Redis idempotency check failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return self::ACQUIRED;
        }
    }

    protected function markProcessed(?string $id): void
    {
        if (!$id || !config('eventbus.idempotency.enabled', true)) {
            return;
        }

        try {
            Redis::setex($this->idempotencyKey($id), (int) config('eventbus.idempotency.ttl', 3600), '1');
        } catch (Throwable $e) {
            Log::error('[EventBus] Could not mark event as processed', [
                'event_id' => $id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function release(?string $id): void
    {
        if (!$id || !config('eventbus.idempotency.enabled', true)) {
            return;
        }

        try {
            Redis::del($this->idempotencyKey($id));
        } catch (Throwable $e) {
            Log::error('[EventBus] Could not release idempotency key', [
                'event_id' => $id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function dispatch(string $handler, array $data): void
    {
        if (!class_exists($handler)) {
            throw new RuntimeException("Handler not found: {$handler}");
        }

        if (method_exists($handler, 'dispatch')) {
            $params = $this->mapConstructor($handler, $data);
            $handler::dispatch(...$params);
            return;
        }

        $instance = app($handler);

        if (!method_exists($instance, 'handle')) {
            throw new RuntimeException("Invalid handler: {$handler} (missing handle method)");
        }

        $instance->handle($data);
    }

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
                        'payload' => $data,
                        default => $data[$name] ?? null,
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
