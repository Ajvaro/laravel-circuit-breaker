<?php

declare(strict_types=1);

namespace Ajvaro\CircuitBreaker;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Ajvaro\CircuitBreaker\Contracts\Store;
use Ajvaro\CircuitBreaker\Stores\DatabaseStore;
use Ajvaro\CircuitBreaker\Stores\RedisStore;

class CircuitBreakerManager
{
    /** @var array<string, CircuitBreaker> */
    protected array $circuits = [];

    /** @var array<string, Store> */
    protected array $stores = [];

    /** @var array<string, Closure(array<string,mixed>, Container):Store> */
    protected array $customDrivers = [];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected readonly Container $container,
        protected array $config,
    ) {
    }

    /**
     * Resolve (and cache) the named circuit breaker.
     */
    public function for(string $name): CircuitBreaker
    {
        return $this->circuits[$name] ??= new CircuitBreaker(
            $name,
            $this->store($this->storeNameFor($name)),
            $this->configFor($name),
            $this->container->bound('events') ? $this->container->make('events') : null,
        );
    }

    /**
     * Convenience proxy: run an action through a named circuit.
     *
     * @template TReturn
     * @param  callable():TReturn  $action
     * @param  (callable(\Throwable):TReturn)|null  $fallback
     * @return TReturn
     */
    public function call(string $name, callable $action, ?callable $fallback = null): mixed
    {
        return $this->for($name)->call($action, $fallback);
    }

    /**
     * Resolve (and cache) a configured store by name.
     */
    public function store(?string $name = null): Store
    {
        $name ??= $this->config['default'];

        return $this->stores[$name] ??= $this->resolveStore($name);
    }

    /**
     * Register a custom store driver.
     *
     * @param  Closure(array<string,mixed>, Container):Store  $resolver
     */
    public function extend(string $driver, Closure $resolver): static
    {
        $this->customDrivers[$driver] = $resolver;

        return $this;
    }

    /**
     * Override the store for a circuit (mostly useful in tests).
     */
    public function setStore(string $name, Store $store): static
    {
        $this->stores[$name] = $store;
        unset($this->circuits[$name]);

        return $this;
    }

    protected function resolveStore(string $name): Store
    {
        $config = $this->config['stores'][$name] ?? null;

        if ($config === null) {
            throw new InvalidArgumentException("Circuit breaker store [{$name}] is not configured.");
        }

        $driver = $config['driver'] ?? $name;

        if (isset($this->customDrivers[$driver])) {
            return ($this->customDrivers[$driver])($config, $this->container);
        }

        return match ($driver) {
            'redis' => new RedisStore(
                $this->container->make(RedisFactory::class),
                $config['connection'] ?? null,
                $config['prefix'] ?? 'cb:',
            ),
            'database' => new DatabaseStore(
                $this->container->make(ConnectionResolverInterface::class),
                $config['connection'] ?? null,
                $config['table'] ?? 'circuit_breakers',
            ),
            default => throw new InvalidArgumentException("Unsupported circuit breaker driver [{$driver}]."),
        };
    }

    protected function storeNameFor(string $name): string
    {
        return $this->config['circuits'][$name]['store'] ?? $this->config['default'];
    }

    /**
     * @return array{failure_threshold:int,success_threshold:int,reset_timeout:int,sample_window:int,half_open_max_attempts:int,handle:array<int,class-string<\Throwable>>}
     */
    protected function configFor(string $name): array
    {
        $defaults = $this->config['defaults'];
        $overrides = $this->config['circuits'][$name] ?? [];

        return array_merge($defaults, array_intersect_key($overrides, $defaults));
    }
}
