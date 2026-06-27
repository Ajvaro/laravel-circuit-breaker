<?php

declare(strict_types=1);

namespace Nikola\CircuitBreaker\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Nikola\CircuitBreaker\CircuitBreakerServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            CircuitBreakerServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('circuit-breaker.defaults', [
            'failure_threshold' => 2,
            'success_threshold' => 2,
            'reset_timeout' => 60,
            'sample_window' => 60,
            'handle' => [\Throwable::class],
        ]);
    }
}
