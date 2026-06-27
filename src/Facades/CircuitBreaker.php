<?php

declare(strict_types=1);

namespace Nikola\CircuitBreaker\Facades;

use Illuminate\Support\Facades\Facade;
use Nikola\CircuitBreaker\CircuitBreakerManager;

/**
 * @method static \Nikola\CircuitBreaker\CircuitBreaker for(string $name)
 * @method static mixed call(string $name, callable $action, ?callable $fallback = null)
 * @method static \Nikola\CircuitBreaker\Contracts\Store store(?string $name = null)
 * @method static \Nikola\CircuitBreaker\CircuitBreakerManager extend(string $driver, \Closure $resolver)
 * @method static \Nikola\CircuitBreaker\CircuitBreakerManager setStore(string $name, \Nikola\CircuitBreaker\Contracts\Store $store)
 *
 * @see \Nikola\CircuitBreaker\CircuitBreakerManager
 */
class CircuitBreaker extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CircuitBreakerManager::class;
    }
}
