<?php

declare(strict_types=1);

namespace Nikola\CircuitBreaker\Tests\Support;

use Nikola\CircuitBreaker\Contracts\Store;
use Nikola\CircuitBreaker\State;

/**
 * Simple per-process store used for unit tests.
 */
class InMemoryStore implements Store
{
    /** @var array<string, array{state:State, failures:int, successes:int, failed_at:?int, opened_at:?int}> */
    protected array $circuits = [];

    public function state(string $name): State
    {
        return $this->circuits[$name]['state'] ?? State::Closed;
    }

    public function openedAt(string $name): ?int
    {
        return $this->circuits[$name]['opened_at'] ?? null;
    }

    public function recordFailure(string $name, int $window): int
    {
        $circuit = $this->ensure($name);
        $now = time();

        if ($circuit['failed_at'] === null || ($now - $circuit['failed_at']) > $window) {
            $circuit['failures'] = 1;
            $circuit['failed_at'] = $now;
        } else {
            $circuit['failures']++;
        }

        $this->circuits[$name] = $circuit;

        return $circuit['failures'];
    }

    public function recordSuccess(string $name): int
    {
        $circuit = $this->ensure($name);
        $circuit['successes']++;
        $this->circuits[$name] = $circuit;

        return $circuit['successes'];
    }

    public function transition(string $name, State $to): void
    {
        $this->circuits[$name] = [
            'state' => $to,
            'failures' => 0,
            'successes' => 0,
            'failed_at' => null,
            'opened_at' => $to === State::Open ? time() : null,
        ];
    }

    public function reset(string $name): void
    {
        unset($this->circuits[$name]);
    }

    /**
     * @return array{state:State, failures:int, successes:int, failed_at:?int, opened_at:?int}
     */
    protected function ensure(string $name): array
    {
        return $this->circuits[$name] ??= [
            'state' => State::Closed,
            'failures' => 0,
            'successes' => 0,
            'failed_at' => null,
            'opened_at' => null,
        ];
    }
}
