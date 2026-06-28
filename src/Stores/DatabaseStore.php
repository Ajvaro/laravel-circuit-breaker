<?php

declare(strict_types=1);

namespace Ajvaro\CircuitBreaker\Stores;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Ajvaro\CircuitBreaker\Contracts\Store;
use Ajvaro\CircuitBreaker\State;

class DatabaseStore implements Store
{
    public function __construct(
        protected readonly ConnectionResolverInterface $resolver,
        protected readonly ?string $connection = null,
        protected readonly string $table = 'circuit_breakers',
    ) {
    }

    public function state(string $name): State
    {
        $row = $this->row($name);

        // Fail safe to closed rather than throwing on a missing or unrecognized
        // value: state() is on the hot path of every protected call.
        return $row !== null ? (State::tryFrom($row->state) ?? State::Closed) : State::Closed;
    }

    public function openedAt(string $name): ?int
    {
        $row = $this->row($name);

        return $row !== null && $row->opened_at !== null ? (int) $row->opened_at : null;
    }

    public function recordFailure(string $name, int $window): int
    {
        return $this->mutate($name, function (object $row, int $now) use ($window) {
            if ($row->failed_at === null || ($now - (int) $row->failed_at) > $window) {
                $count = 1;
                $failedAt = $now;
            } else {
                $count = (int) $row->failures + 1;
                $failedAt = (int) $row->failed_at;
            }

            return [$count, [
                'failures' => $count,
                'failed_at' => $failedAt,
                'updated_at' => $now,
            ]];
        });
    }

    public function recordSuccess(string $name): int
    {
        return $this->mutate($name, function (object $row, int $now) {
            $count = (int) $row->successes + 1;

            return [$count, [
                'successes' => $count,
                'updated_at' => $now,
            ]];
        });
    }

    public function incrementInFlight(string $name, int $ttl): int
    {
        return $this->mutate($name, function (object $row, int $now) use ($ttl) {
            $current = (int) $row->in_flight;

            // Self-heal slots leaked by a probe that never released (e.g. a crash).
            if ($ttl > 0 && $row->updated_at !== null && ($now - (int) $row->updated_at) > $ttl) {
                $current = 0;
            }

            $count = $current + 1;

            return [$count, [
                'in_flight' => $count,
                'updated_at' => $now,
            ]];
        });
    }

    public function decrementInFlight(string $name): void
    {
        $this->table()
            ->where('name', $name)
            ->where('in_flight', '>', 0)
            ->decrement('in_flight', 1, ['updated_at' => time()]);
    }

    public function transition(string $name, State $to): bool
    {
        $values = [
            'state' => $to->value,
            'failures' => 0,
            'successes' => 0,
            'failed_at' => null,
            'opened_at' => $to === State::Open ? time() : null,
            'updated_at' => time(),
        ];

        if ($to === State::Open) {
            // Heal any trial slots leaked by a previous half-open episode.
            $values['in_flight'] = 0;
        }

        // insertOrIgnore + a conditional update rather than updateOrInsert: the
        // latter races two concurrent transitions into a duplicate-key insert.
        // The "state <> target" guard makes the update atomic and idempotent, so
        // its affected-row count tells us whether this caller flipped the state.
        $this->ensureRow($name);

        return $this->table()
            ->where('name', $name)
            ->where('state', '!=', $to->value)
            ->update($values) > 0;
    }

    public function reset(string $name): void
    {
        $this->table()->where('name', $name)->delete();
    }

    /**
     * Atomically read-modify-write the named circuit's counters. The row is
     * created if missing and locked for the duration of the transaction, so
     * concurrent callers serialize instead of clobbering each other's counts.
     *
     * @param  callable(object, int): array{0:int, 1:array<string,mixed>}  $apply
     */
    protected function mutate(string $name, callable $apply): int
    {
        $this->ensureRow($name);

        return $this->connection()->transaction(function () use ($name, $apply) {
            $row = $this->table()->where('name', $name)->lockForUpdate()->first()
                ?? (object) $this->defaults($name);

            [$count, $values] = $apply($row, time());

            $this->table()->where('name', $name)->update($values);

            return $count;
        }, 3);
    }

    /**
     * Ensure a row for the circuit exists without racing on the primary key.
     */
    protected function ensureRow(string $name): void
    {
        $this->table()->insertOrIgnore(array_merge($this->defaults($name), [
            'updated_at' => time(),
        ]));
    }

    protected function row(string $name): ?object
    {
        return $this->table()->where('name', $name)->first();
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(string $name): array
    {
        return [
            'name' => $name,
            'state' => State::Closed->value,
            'failures' => 0,
            'successes' => 0,
            'in_flight' => 0,
            'failed_at' => null,
            'opened_at' => null,
        ];
    }

    protected function connection(): ConnectionInterface
    {
        return $this->resolver->connection($this->connection);
    }

    protected function table(): Builder
    {
        return $this->connection()->table($this->table);
    }
}
