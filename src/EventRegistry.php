<?php

namespace Company\EventBus;

class EventRegistry
{
    protected array $handlers = [];

    public function __construct(array $handlers = [])
    {
        $this->handlers = $handlers;
    }

    public function register(string $event, string $handler): void
    {
        $this->handlers[$event] = $handler;
    }

    public function getHandler(string $event): ?string
    {
        return $this->handlers[$event] ?? null;
    }

    public function all(): array
    {
        return $this->handlers;
    }
}