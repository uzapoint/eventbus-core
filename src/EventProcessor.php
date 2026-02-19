<?php

namespace Uzapoint\EventBus;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use PhpAmqpLib\Message\AMQPMessage;
use ReflectionClass;

class EventProcessor
{
    public function __construct(
        protected EventRegistry $registry
    ) {}

    /**
     * @throws \ReflectionException
     */
    public function process(AMQPMessage $message): void
    {
        $routingKey = $message->getRoutingKey();
        $payload = json_decode($message->getBody(), true);

        if (!$payload || !isset($payload['data'])) {
            Log::error('[EventBus] Invalid message envelope');
            return;
        }

        $meta = $payload['meta'] ?? [];
        $data = $payload['data'];

        $id = $meta['id'] ?? null;

        if ($this->isDuplicate($id)) {
            return;
        }

        $handler = $this->registry->getHandler($routingKey);

        if (!$handler) {
            return;
        }

        $this->dispatch($handler, $data);
    }

    protected function isDuplicate(?string $id): bool
    {
        if (!$id) return false;

        $key = config('eventbus.idempotency.redis_prefix') . $id;

        if (Redis::get($key)) {
            return true;
        }

        Redis::setex($key, config('eventbus.idempotency.ttl'), 1);

        return false;
    }

    /**
     * @throws \ReflectionException
     */
    protected function dispatch(string $handler, array $data): void
    {
        if (method_exists($handler, 'dispatch')) {
            $params = $this->mapConstructor($handler, $data);
            $handler::dispatch(...$params);
            return;
        }

        app($handler)->handle($data);
    }

    /**
     * @throws \ReflectionException
     */
    protected function mapConstructor(string $class, array $data): array
    {
        try {
            $reflection = new ReflectionClass($class);
            $constructor = $reflection->getConstructor();

            if (!$constructor) return [];

            return collect($constructor->getParameters())
                ->map(fn($param) => $data[$param->getName()] ?? null)
                ->toArray();
        } catch (\ReflectionException $e) {
            Log::error($e->getMessage());
            return [];
        }
    }
}