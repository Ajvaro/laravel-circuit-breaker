<?php

declare(strict_types=1);

namespace Nikola\CircuitBreaker;

use Illuminate\Contracts\Events\Dispatcher;
use Nikola\CircuitBreaker\Contracts\Store;
use Nikola\CircuitBreaker\Events\CircuitClosed;
use Nikola\CircuitBreaker\Events\CircuitHalfOpened;
use Nikola\CircuitBreaker\Events\CircuitOpened;
use Nikola\CircuitBreaker\Exceptions\CircuitOpenException;
use Throwable;

class CircuitBreaker
{
    /**
     * @param  array{failure_threshold:int,success_threshold:int,reset_timeout:int,sample_window:int,handle:array<int,class-string<Throwable>>}  $config
     */
    public function __construct(
        protected readonly string $name,
        protected readonly Store $store,
        protected readonly array $config,
        protected readonly ?Dispatcher $events = null,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * Execute the protected action, applying circuit breaker semantics.
     *
     * @template TReturn
     * @param  callable():TReturn  $action
     * @param  (callable(Throwable):TReturn)|null  $fallback  Invoked when the call is rejected or fails.
     * @return TReturn
     *
     * @throws CircuitOpenException When the circuit is open and no fallback is given.
     * @throws Throwable When the action throws and no fallback is given.
     */
    public function call(callable $action, ?callable $fallback = null): mixed
    {
        if (! $this->allowsRequest()) {
            $exception = new CircuitOpenException($this->name);

            if ($fallback !== null) {
                return $fallback($exception);
            }

            throw $exception;
        }

        try {
            $result = $action();
        } catch (Throwable $e) {
            if ($this->shouldHandle($e)) {
                $this->recordFailure();

                if ($fallback !== null) {
                    return $fallback($e);
                }
            }

            throw $e;
        }

        $this->recordSuccess();

        return $result;
    }

    /**
     * Whether a request is currently allowed through the circuit.
     * Side effect: transitions an expired open circuit to half-open.
     */
    public function allowsRequest(): bool
    {
        $state = $this->store->state($this->name);

        if ($state === State::Open) {
            if ($this->timeoutHasElapsed()) {
                $this->toHalfOpen();

                return true;
            }

            return false;
        }

        return true;
    }

    public function state(): State
    {
        return $this->store->state($this->name);
    }

    public function isAvailable(): bool
    {
        $state = $this->store->state($this->name);

        return $state !== State::Open || $this->timeoutHasElapsed();
    }

    public function recordFailure(): void
    {
        $state = $this->store->state($this->name);
        $count = $this->store->recordFailure($this->name, $this->config['sample_window']);

        if ($state === State::HalfOpen) {
            $this->toOpen();

            return;
        }

        if ($count >= $this->config['failure_threshold']) {
            $this->toOpen();
        }
    }

    public function recordSuccess(): void
    {
        if ($this->store->state($this->name) !== State::HalfOpen) {
            return;
        }

        $count = $this->store->recordSuccess($this->name);

        if ($count >= $this->config['success_threshold']) {
            $this->toClosed();
        }
    }

    /**
     * Forcefully reset the circuit back to the closed state.
     */
    public function reset(): void
    {
        $this->store->reset($this->name);
    }

    protected function timeoutHasElapsed(): bool
    {
        $openedAt = $this->store->openedAt($this->name);

        if ($openedAt === null) {
            return true;
        }

        return (time() - $openedAt) >= $this->config['reset_timeout'];
    }

    protected function shouldHandle(Throwable $e): bool
    {
        foreach ($this->config['handle'] as $type) {
            if ($e instanceof $type) {
                return true;
            }
        }

        return false;
    }

    protected function toOpen(): void
    {
        $this->store->transition($this->name, State::Open);
        $this->dispatch(new CircuitOpened($this->name));
    }

    protected function toHalfOpen(): void
    {
        $this->store->transition($this->name, State::HalfOpen);
        $this->dispatch(new CircuitHalfOpened($this->name));
    }

    protected function toClosed(): void
    {
        $this->store->transition($this->name, State::Closed);
        $this->dispatch(new CircuitClosed($this->name));
    }

    protected function dispatch(object $event): void
    {
        $this->events?->dispatch($event);
    }
}
