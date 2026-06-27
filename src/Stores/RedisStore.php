<?php

declare(strict_types=1);

namespace Nikola\CircuitBreaker\Stores;

use Illuminate\Contracts\Redis\Connection;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Nikola\CircuitBreaker\Contracts\Store;
use Nikola\CircuitBreaker\State;

class RedisStore implements Store
{
    public function __construct(
        protected readonly RedisFactory $redis,
        protected readonly ?string $connection = null,
        protected readonly string $prefix = 'cb:',
    ) {
    }

    public function state(string $name): State
    {
        $value = $this->connection()->get($this->key($name, 'state'));

        return $value !== null ? State::from((string) $value) : State::Closed;
    }

    public function openedAt(string $name): ?int
    {
        $value = $this->connection()->get($this->key($name, 'opened_at'));

        return $value !== null ? (int) $value : null;
    }

    public function recordFailure(string $name, int $window): int
    {
        $key = $this->key($name, 'failures');
        $count = (int) $this->connection()->incr($key);

        if ($count === 1) {
            $this->connection()->expire($key, $window);
        }

        return $count;
    }

    public function recordSuccess(string $name): int
    {
        return (int) $this->connection()->incr($this->key($name, 'successes'));
    }

    public function transition(string $name, State $to): void
    {
        $connection = $this->connection();

        $connection->set($this->key($name, 'state'), $to->value);
        $connection->del($this->key($name, 'failures'));
        $connection->del($this->key($name, 'successes'));

        if ($to === State::Open) {
            $connection->set($this->key($name, 'opened_at'), (string) time());
        } else {
            $connection->del($this->key($name, 'opened_at'));
        }
    }

    public function reset(string $name): void
    {
        $this->connection()->del(
            $this->key($name, 'state'),
            $this->key($name, 'failures'),
            $this->key($name, 'successes'),
            $this->key($name, 'opened_at'),
        );
    }

    protected function connection(): Connection
    {
        return $this->redis->connection($this->connection);
    }

    protected function key(string $name, string $suffix): string
    {
        return "{$this->prefix}{$name}:{$suffix}";
    }
}
