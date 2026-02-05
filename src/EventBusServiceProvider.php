<?php

namespace Company\EventBus;

use Illuminate\Support\ServiceProvider;
use Company\EventBus\Console\ConsumeRabbitMQEvents;

class EventBusServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/eventbus.php',
            'eventbus'
        );

        $this->app->singleton(EventRegistry::class, function () {
            return new EventRegistry(config('eventbus.handlers', []));
        });

        $this->app->singleton(RabbitMQService::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ConsumeRabbitMQEvents::class
            ]);

            $this->publishes([
                __DIR__ . '/../config/eventbus.php' => config_path('eventbus.php'),
            ], 'eventbus-config');
        }
    }
}