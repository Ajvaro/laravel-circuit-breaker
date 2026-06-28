<?php

declare(strict_types=1);

namespace Ajvaro\CircuitBreaker\Tests;

use Illuminate\Support\Facades\Redis;
use Ajvaro\CircuitBreaker\Facades\CircuitBreaker;
use Ajvaro\CircuitBreaker\State;
use Ajvaro\CircuitBreaker\Stores\RedisStore;
use RuntimeException;
use Throwable;

class RedisStoreTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('circuit-breaker.default', 'redis');
        $app['config']->set('database.redis.client', env('REDIS_CLIENT', 'phpredis'));
        $app['config']->set('database.redis.default', [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
            'database' => env('REDIS_TEST_DB', 15),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        try {
            Redis::connection()->flushdb();
        } catch (Throwable $e) {
            $this->markTestSkipped('Redis is not available: '.$e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        try {
            Redis::connection()->flushdb();
        } catch (Throwable) {
            // ignore
        }

        parent::tearDown();
    }

    private function store(): RedisStore
    {
        return new RedisStore($this->app->make('redis'));
    }

    public function test_transition_reports_whether_the_state_changed(): void
    {
        $store = $this->store();

        // Exercises the GETSET-based change detection on a real connection.
        $this->assertTrue($store->transition('svc', State::Open));
        $this->assertFalse($store->transition('svc', State::Open));
        $this->assertTrue($store->transition('svc', State::HalfOpen));
    }

    public function test_record_failure_counts_within_a_ttl_window(): void
    {
        $store = $this->store();

        $this->assertSame(1, $store->recordFailure('svc', 60));
        $this->assertSame(2, $store->recordFailure('svc', 60));

        // The window is enforced by a TTL on the failures key (set on the first hit).
        $this->assertGreaterThan(0, Redis::connection()->ttl('cb:svc:failures'));
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

    public function test_unrecognized_stored_state_falls_back_to_closed(): void
    {
        Redis::connection()->set('cb:svc:state', 'bogus');

        // A corrupt value must not throw on the hot path; it degrades to closed.
        $this->assertSame(State::Closed, $this->store()->state('svc'));
    }

    public function test_full_lifecycle_through_the_redis_store(): void
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

        $result = $breaker->call(fn () => 'recovered');

        $this->assertSame('recovered', $result);
        $this->assertSame(State::Closed, $breaker->state());
    }
}
