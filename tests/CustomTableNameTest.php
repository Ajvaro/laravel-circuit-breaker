<?php

declare(strict_types=1);

namespace Nikola\CircuitBreaker\Tests;

use Illuminate\Support\Facades\Schema;
use Nikola\CircuitBreaker\Facades\CircuitBreaker;
use Nikola\CircuitBreaker\State;
use RuntimeException;

class CustomTableNameTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('circuit-breaker.default', 'database');
        $app['config']->set('circuit-breaker.stores.database.table', 'cb_breakers');
    }

    public function test_migration_and_store_honor_the_configured_table_name(): void
    {
        $this->assertTrue(Schema::hasTable('cb_breakers'));
        $this->assertFalse(Schema::hasTable('circuit_breakers'));

        config()->set('circuit-breaker.circuits.api', ['failure_threshold' => 2]);

        $breaker = CircuitBreaker::for('api');

        for ($i = 0; $i < 2; $i++) {
            try {
                $breaker->call(fn () => throw new RuntimeException('boom'));
            } catch (RuntimeException) {
                // expected
            }
        }

        $this->assertSame(State::Open, $breaker->state());
        $this->assertDatabaseHas('cb_breakers', ['name' => 'api', 'state' => State::Open->value]);
    }
}
