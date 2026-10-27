<?php

namespace Uzapoint\EventBus;

use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Support\ServiceProvider;
use Uzapoint\EventBus\Console\ConsumeRabbitMQEvents;
use Uzapoint\EventBus\Console\DoctorCommand;

class EventBusServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigRecursively(__DIR__ . '/../config/eventbus.php', 'eventbus');

        $this->app->singleton(EventRegistry::class, function () {
            return new EventRegistry(config('eventbus.handlers', []));
        });

        $this->app->singleton(RabbitMQService::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ConsumeRabbitMQEvents::class,
                DoctorCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/eventbus.php' => config_path('eventbus.php'),
            ], 'eventbus-config');
        }
    }

    protected function mergeConfigRecursively(string $path, string $key): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return; // the cached config was built from an already merged repository
        }

        $config = $this->app->make('config');

        $config->set($key, array_replace_recursive(require $path, $config->get($key, [])));
    }
}
