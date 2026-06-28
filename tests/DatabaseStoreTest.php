<?php

declare(strict_types=1);

namespace Nikola\CircuitBreaker\Tests;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Event;
use Nikola\CircuitBreaker\Events\CircuitOpened;
use Nikola\CircuitBreaker\Facades\CircuitBreaker;
use Nikola\CircuitBreaker\State;
use Nikola\CircuitBreaker\Stores\DatabaseStore;
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

    private function store(): DatabaseStore
    {
        return new DatabaseStore($this->app->make(ConnectionResolverInterface::class));
    }

    public function test_record_failure_counts_within_window_and_resets_once_it_elapses(): void
    {
        $store = $this->store();

        $this->assertSame(1, $store->recordFailure('svc', 60)); // first failure creates the row
        $this->assertSame(2, $store->recordFailure('svc', 60)); // still within the window

        // Backdate the window start so the next failure is treated as a fresh window.
        $this->app->make(ConnectionResolverInterface::class)->connection()
            ->table('circuit_breakers')->where('name', 'svc')->update(['failed_at' => time() - 120]);

        $this->assertSame(1, $store->recordFailure('svc', 60));
    }

    public function test_transition_reports_whether_the_state_changed(): void
    {
        $store = $this->store();

        $this->assertTrue($store->transition('svc', State::Open));      // created as closed -> open
        $this->assertFalse($store->transition('svc', State::Open));     // already open
        $this->assertTrue($store->transition('svc', State::HalfOpen));  // open -> half-open
    }

    public function test_unrecognized_stored_state_falls_back_to_closed(): void
    {
        $this->app->make(ConnectionResolverInterface::class)->connection()
            ->table('circuit_breakers')->insert(['name' => 'svc', 'state' => 'bogus']);

        // A corrupt value must not throw on the hot path; it degrades to closed.
        $this->assertSame(State::Closed, $this->store()->state('svc'));
    }

    public function test_record_success_increments(): void
    {
        $store = $this->store();

        $this->assertSame(1, $store->recordSuccess('svc'));
        $this->assertSame(2, $store->recordSuccess('svc'));
        $this->assertSame(3, $store->recordSuccess('svc'));
    }

    public function test_in_flight_increments_and_decrements_without_going_negative(): void
    {
        $store = $this->store();

        $this->assertSame(1, $store->incrementInFlight('svc', 60));
        $this->assertSame(2, $store->incrementInFlight('svc', 60));

        $store->decrementInFlight('svc');
        $this->assertSame(2, $store->incrementInFlight('svc', 60));

        // More releases than acquisitions must clamp at zero, not underflow.
        $store->decrementInFlight('svc');
        $store->decrementInFlight('svc');
        $store->decrementInFlight('svc');
        $this->assertSame(1, $store->incrementInFlight('svc', 60));
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
