<?php

declare(strict_types=1);

namespace Ajvaro\CircuitBreaker\Facades;

use Illuminate\Support\Facades\Facade;
use Ajvaro\CircuitBreaker\CircuitBreakerManager;

/**
 * @method static \Ajvaro\CircuitBreaker\CircuitBreaker for(string $name)
 * @method static mixed call(string $name, callable $action, ?callable $fallback = null)
 * @method static \Ajvaro\CircuitBreaker\Contracts\Store store(?string $name = null)
 * @method static \Ajvaro\CircuitBreaker\CircuitBreakerManager extend(string $driver, \Closure $resolver)
 * @method static \Ajvaro\CircuitBreaker\CircuitBreakerManager setStore(string $name, \Ajvaro\CircuitBreaker\Contracts\Store $store)
 *
 * @see \Ajvaro\CircuitBreaker\CircuitBreakerManager
 */
class CircuitBreaker extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CircuitBreakerManager::class;
    }
}
