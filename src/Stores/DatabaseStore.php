<?php

declare(strict_types=1);

namespace Nikola\CircuitBreaker\Stores;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Nikola\CircuitBreaker\Contracts\Store;
use Nikola\CircuitBreaker\State;

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

        return $row !== null ? State::from($row->state) : State::Closed;
    }

    public function openedAt(string $name): ?int
    {
        $row = $this->row($name);

        return $row !== null && $row->opened_at !== null ? (int) $row->opened_at : null;
    }

    public function recordFailure(string $name, int $window): int
    {
        $now = time();
        $row = $this->row($name);

        if ($row === null) {
            $this->table()->insert(array_merge($this->defaults($name), [
                'failures' => 1,
                'failed_at' => $now,
                'updated_at' => $now,
            ]));

            return 1;
        }

        if ($row->failed_at === null || ($now - (int) $row->failed_at) > $window) {
            $count = 1;
            $failedAt = $now;
        } else {
            $count = (int) $row->failures + 1;
            $failedAt = (int) $row->failed_at;
        }

        $this->table()->where('name', $name)->update([
            'failures' => $count,
            'failed_at' => $failedAt,
            'updated_at' => $now,
        ]);

        return $count;
    }

    public function recordSuccess(string $name): int
    {
        $now = time();
        $row = $this->row($name);

        if ($row === null) {
            $this->table()->insert(array_merge($this->defaults($name), [
                'successes' => 1,
                'updated_at' => $now,
            ]));

            return 1;
        }

        $count = (int) $row->successes + 1;

        $this->table()->where('name', $name)->update([
            'successes' => $count,
            'updated_at' => $now,
        ]);

        return $count;
    }

    public function incrementInFlight(string $name, int $ttl): int
    {
        return $this->connection()->transaction(function () use ($name, $ttl) {
            $now = time();
            $row = $this->table()->where('name', $name)->lockForUpdate()->first();

            if ($row === null) {
                $this->table()->insert(array_merge($this->defaults($name), [
                    'in_flight' => 1,
                    'updated_at' => $now,
                ]));

                return 1;
            }

            $current = (int) $row->in_flight;

            // Self-heal slots leaked by a probe that never released (e.g. a crash).
            if ($ttl > 0 && $row->updated_at !== null && ($now - (int) $row->updated_at) > $ttl) {
                $current = 0;
            }

            $count = $current + 1;

            $this->table()->where('name', $name)->update([
                'in_flight' => $count,
                'updated_at' => $now,
            ]);

            return $count;
        });
    }

    public function decrementInFlight(string $name): void
    {
        $this->table()
            ->where('name', $name)
            ->where('in_flight', '>', 0)
            ->decrement('in_flight', 1, ['updated_at' => time()]);
    }

    public function transition(string $name, State $to): void
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

        $this->table()->updateOrInsert(['name' => $name], $values);
    }

    public function reset(string $name): void
    {
        $this->table()->where('name', $name)->delete();
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
