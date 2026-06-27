<?php

declare(strict_types=1);

namespace Nikola\CircuitBreaker\Contracts;

use Nikola\CircuitBreaker\State;

interface Store
{
    /**
     * Current state of the named circuit (defaults to closed).
     */
    public function state(string $name): State;

    /**
     * Unix timestamp of when the circuit was last opened, or null.
     */
    public function openedAt(string $name): ?int;

    /**
     * Record a failure within the given rolling window (seconds) and return the new count.
     */
    public function recordFailure(string $name, int $window): int;

    /**
     * Record a success and return the new count.
     */
    public function recordSuccess(string $name): int;

    /**
     * Move the circuit to the given state, resetting counters. Opening also stamps openedAt.
     */
    public function transition(string $name, State $to): void;

    /**
     * Forget all data for the circuit (returns it to the default closed state).
     */
    public function reset(string $name): void;
}
