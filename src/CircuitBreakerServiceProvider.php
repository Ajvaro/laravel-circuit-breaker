<?php

declare(strict_types=1);

namespace Ajvaro\CircuitBreaker;

use Illuminate\Support\ServiceProvider;

class CircuitBreakerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/circuit-breaker.php', 'circuit-breaker');

        $this->app->singleton(CircuitBreakerManager::class, function ($app) {
            return new CircuitBreakerManager($app, $app['config']['circuit-breaker']);
        });

        $this->app->alias(CircuitBreakerManager::class, 'circuit-breaker');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/circuit-breaker.php' => $this->app->configPath('circuit-breaker.php'),
            ], 'circuit-breaker-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], 'circuit-breaker-migrations');
        }
    }

    /**
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [CircuitBreakerManager::class, 'circuit-breaker'];
    }
}
