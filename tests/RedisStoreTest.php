<?php

declare(strict_types=1);

namespace Nikola\CircuitBreaker\Tests;

use Illuminate\Support\Facades\Redis;
use Nikola\CircuitBreaker\Facades\CircuitBreaker;
use Nikola\CircuitBreaker\State;
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
