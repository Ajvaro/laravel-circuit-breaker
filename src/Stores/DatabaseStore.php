<?php

declare(strict_types=1);

namespace Nikola\CircuitBreaker\Stores;

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

    public function transition(string $name, State $to): void
    {
        $this->table()->updateOrInsert(
            ['name' => $name],
            [
                'state' => $to->value,
                'failures' => 0,
                'successes' => 0,
                'failed_at' => null,
                'opened_at' => $to === State::Open ? time() : null,
                'updated_at' => time(),
            ],
        );
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
            'failed_at' => null,
            'opened_at' => null,
        ];
    }

    protected function table(): Builder
    {
        return $this->resolver->connection($this->connection)->table($this->table);
    }
}
