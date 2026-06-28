<?php

declare(strict_types=1);

namespace Nikola\CircuitBreaker\Tests;

use Illuminate\Contracts\Events\Dispatcher;
use Nikola\CircuitBreaker\CircuitBreaker;
use Nikola\CircuitBreaker\Contracts\Store;
use Nikola\CircuitBreaker\Events\CircuitOpened;
use Nikola\CircuitBreaker\Exceptions\CircuitOpenException;
use Nikola\CircuitBreaker\State;
use Nikola\CircuitBreaker\Tests\Support\InMemoryStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CircuitBreakerTest extends TestCase
{
    private function breaker(Store $store, array $overrides = [], ?Dispatcher $events = null): CircuitBreaker
    {
        return new CircuitBreaker('service', $store, array_merge([
            'failure_threshold' => 2,
            'success_threshold' => 2,
            'reset_timeout' => 60,
            'sample_window' => 60,
            'half_open_max_attempts' => 1,
            'handle' => [\Throwable::class],
        ], $overrides), $events);
    }

    public function test_successful_calls_pass_through_and_keep_circuit_closed(): void
    {
        $breaker = $this->breaker(new InMemoryStore());

        $result = $breaker->call(fn () => 'ok');

        $this->assertSame('ok', $result);
        $this->assertSame(State::Closed, $breaker->state());
    }

    public function test_circuit_opens_after_failure_threshold(): void
    {
        $breaker = $this->breaker(new InMemoryStore());

        for ($i = 0; $i < 2; $i++) {
            try {
                $breaker->call(fn () => throw new RuntimeException('boom'));
            } catch (RuntimeException) {
                // expected
            }
        }

        $this->assertSame(State::Open, $breaker->state());
    }

    public function test_open_circuit_fails_fast(): void
    {
        $store = new InMemoryStore();
        $store->transition('service', State::Open);
        $breaker = $this->breaker($store);

        $called = false;

        $this->expectException(CircuitOpenException::class);

        $breaker->call(function () use (&$called) {
            $called = true;

            return 'never';
        });

        $this->assertFalse($called);
    }

    public function test_fallback_is_used_when_circuit_is_open(): void
    {
        $store = new InMemoryStore();
        $store->transition('service', State::Open);
        $breaker = $this->breaker($store);

        $result = $breaker->call(
            fn () => 'never',
            fn ($e) => 'fallback',
        );

        $this->assertSame('fallback', $result);
    }

    public function test_fallback_is_used_when_action_fails(): void
    {
        $breaker = $this->breaker(new InMemoryStore());

        $result = $breaker->call(
            fn () => throw new RuntimeException('boom'),
            fn ($e) => 'fallback',
        );

        $this->assertSame('fallback', $result);
    }

    public function test_open_circuit_transitions_to_half_open_after_timeout(): void
    {
        $store = new InMemoryStore();
        $store->transition('service', State::Open);
        $breaker = $this->breaker($store, ['reset_timeout' => 0]);

        $result = $breaker->call(fn () => 'trial');

        $this->assertSame('trial', $result);
        $this->assertSame(State::HalfOpen, $breaker->state());
    }

    public function test_circuit_closes_after_enough_successes_in_half_open(): void
    {
        $store = new InMemoryStore();
        $store->transition('service', State::HalfOpen);
        $breaker = $this->breaker($store, ['success_threshold' => 2]);

        $breaker->call(fn () => 'one');
        $this->assertSame(State::HalfOpen, $breaker->state());

        $breaker->call(fn () => 'two');
        $this->assertSame(State::Closed, $breaker->state());
    }

    public function test_circuit_reopens_on_failure_in_half_open(): void
    {
        $store = new InMemoryStore();
        $store->transition('service', State::HalfOpen);
        $breaker = $this->breaker($store);

        try {
            $breaker->call(fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(State::Open, $breaker->state());
    }

    public function test_half_open_rejects_trial_requests_beyond_the_limit(): void
    {
        $store = new InMemoryStore();
        $store->transition('service', State::HalfOpen);
        $breaker = $this->breaker($store, [
            'half_open_max_attempts' => 1,
            'success_threshold' => 5,
        ]);

        $concurrent = null;

        // While this probe is in flight, a second concurrent request must be
        // rejected rather than piling onto the recovering dependency.
        $result = $breaker->call(function () use ($breaker, &$concurrent) {
            $concurrent = $breaker->call(
                fn () => 'second',
                fn ($e) => $e,
            );

            return 'first';
        });

        $this->assertSame('first', $result);
        $this->assertInstanceOf(CircuitOpenException::class, $concurrent);
    }

    public function test_half_open_allows_sequential_trial_requests(): void
    {
        $store = new InMemoryStore();
        $store->transition('service', State::HalfOpen);
        $breaker = $this->breaker($store, ['half_open_max_attempts' => 1]);

        // Each probe releases its slot, so the next sequential trial is admitted.
        $this->assertSame('one', $breaker->call(fn () => 'one'));
        $this->assertSame('two', $breaker->call(fn () => 'two'));
    }

    public function test_unhandled_exceptions_do_not_count_as_failures(): void
    {
        $breaker = $this->breaker(new InMemoryStore(), ['handle' => [\LogicException::class]]);

        for ($i = 0; $i < 3; $i++) {
            try {
                $breaker->call(fn () => throw new RuntimeException('boom'));
            } catch (RuntimeException) {
                // expected
            }
        }

        $this->assertSame(State::Closed, $breaker->state());
    }

    public function test_transition_reports_whether_the_state_changed(): void
    {
        $store = new InMemoryStore();

        $this->assertTrue($store->transition('service', State::Open));   // closed -> open
        $this->assertFalse($store->transition('service', State::Open));  // already open
        $this->assertTrue($store->transition('service', State::Closed)); // open -> closed
    }

    public function test_transition_event_is_dispatched_only_once_when_callers_race(): void
    {
        // A store that always reports half-open forces every recordFailure() to
        // attempt the half-open -> open transition, standing in for several
        // requests racing to open the circuit at the same time.
        $store = new class extends InMemoryStore {
            public function state(string $name): State
            {
                return State::HalfOpen;
            }
        };

        $dispatched = [];
        $events = $this->createMock(Dispatcher::class);
        $events->method('dispatch')->willReturnCallback(function ($event) use (&$dispatched) {
            $dispatched[] = $event;

            return null;
        });

        $breaker = $this->breaker($store, [], $events);

        $breaker->recordFailure();
        $breaker->recordFailure();
        $breaker->recordFailure();

        $opened = array_filter($dispatched, fn ($e) => $e instanceof CircuitOpened);

        $this->assertCount(1, $opened);
    }
}
