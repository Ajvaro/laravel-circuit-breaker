<?php

declare(strict_types=1);

namespace Ajvaro\CircuitBreaker\Stores;

use Illuminate\Contracts\Redis\Connection;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Ajvaro\CircuitBreaker\Contracts\Store;
use Ajvaro\CircuitBreaker\State;

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

        // Fail safe to closed rather than throwing on a missing or unrecognized
        // value: state() is on the hot path of every protected call.
        return $value !== null ? (State::tryFrom((string) $value) ?? State::Closed) : State::Closed;
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

    public function incrementInFlight(string $name, int $ttl): int
    {
        $key = $this->key($name, 'in_flight');
        $count = (int) $this->connection()->incr($key);

        if ($count === 1 && $ttl > 0) {
            $this->connection()->expire($key, $ttl);
        }

        return $count;
    }

    public function decrementInFlight(string $name): void
    {
        $key = $this->key($name, 'in_flight');

        if ((int) $this->connection()->decr($key) < 0) {
            $this->connection()->del($key);
        }
    }

    public function transition(string $name, State $to): bool
    {
        $connection = $this->connection();

        // GETSET atomically swaps the state and hands back the previous value, so
        // exactly one of several racing callers observes the change.
        $previous = $connection->getset($this->key($name, 'state'), $to->value);
        $changed = ($previous === null ? State::Closed : (State::tryFrom((string) $previous) ?? State::Closed)) !== $to;

        if (! $changed) {
            return false;
        }

        $connection->del($this->key($name, 'failures'));
        $connection->del($this->key($name, 'successes'));

        if ($to === State::Open) {
            $connection->set($this->key($name, 'opened_at'), (string) time());
            // Heal any trial slots leaked by a previous half-open episode.
            $connection->del($this->key($name, 'in_flight'));
        } else {
            $connection->del($this->key($name, 'opened_at'));
        }

        return true;
    }

    public function reset(string $name): void
    {
        $this->connection()->del(
            $this->key($name, 'state'),
            $this->key($name, 'failures'),
            $this->key($name, 'successes'),
            $this->key($name, 'opened_at'),
            $this->key($name, 'in_flight'),
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
