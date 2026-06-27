<?php

declare(strict_types=1);

namespace Nikola\CircuitBreaker\Tests;

use Illuminate\Support\Facades\Event;
use Nikola\CircuitBreaker\Events\CircuitOpened;
use Nikola\CircuitBreaker\Facades\CircuitBreaker;
use Nikola\CircuitBreaker\State;
use RuntimeException;

class DatabaseStoreTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('circuit-breaker.default', 'database');
    }

    public function test_circuit_state_is_persisted_in_the_database(): void
    {
        Event::fake([CircuitOpened::class]);

        $breaker = CircuitBreaker::for('payments');

        for ($i = 0; $i < 2; $i++) {
            try {
                $breaker->call(fn () => throw new RuntimeException('boom'));
            } catch (RuntimeException) {
                // expected
            }
        }

        $this->assertSame(State::Open, $breaker->state());
        $this->assertDatabaseHas('circuit_breakers', [
            'name' => 'payments',
            'state' => State::Open->value,
        ]);

        Event::assertDispatched(CircuitOpened::class);
    }

    public function test_full_lifecycle_through_the_database_store(): void
    {
        config()->set('circuit-breaker.circuits.api', [
            'failure_threshold' => 2,
            'success_threshold' => 1,
            'reset_timeout' => 0,
        ]);

        $breaker = CircuitBreaker::for('api');

        for ($i = 0; $i < 2; $i++) {
            try {
                $breaker->call(fn () => throw new RuntimeException('boom'));
            } catch (RuntimeException) {
                // expected
            }
        }

        $this->assertSame(State::Open, $breaker->state());

        // reset_timeout is 0, so the next call is a half-open trial that succeeds and closes.
        $result = $breaker->call(fn () => 'recovered');

        $this->assertSame('recovered', $result);
        $this->assertSame(State::Closed, $breaker->state());
    }
}
