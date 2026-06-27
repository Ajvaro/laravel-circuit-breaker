<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Store
    |--------------------------------------------------------------------------
    |
    | The store used to persist circuit state across requests. Supported
    | drivers out of the box: "redis" and "database".
    |
    */

    'default' => env('CIRCUIT_BREAKER_STORE', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Stores
    |--------------------------------------------------------------------------
    */

    'stores' => [

        'redis' => [
            'driver' => 'redis',
            'connection' => env('CIRCUIT_BREAKER_REDIS_CONNECTION', 'default'),
            'prefix' => 'cb:',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('CIRCUIT_BREAKER_DB_CONNECTION'),
            'table' => 'circuit_breakers',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Default Circuit Settings
    |--------------------------------------------------------------------------
    |
    | failure_threshold : consecutive/windowed failures before the circuit opens.
    | success_threshold : successes in half-open state before the circuit closes.
    | reset_timeout     : seconds a circuit stays open before a trial (half-open).
    | sample_window     : seconds over which failures are counted while closed.
    | handle            : exception types that count as failures.
    |
    */

    'defaults' => [
        'failure_threshold' => 5,
        'success_threshold' => 2,
        'reset_timeout' => 60,
        'sample_window' => 60,
        'handle' => [
            \Throwable::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-Circuit Overrides
    |--------------------------------------------------------------------------
    |
    | Override any default per named circuit, and optionally pin a circuit to a
    | specific store via the "store" key.
    |
    |   'payments' => [
    |       'store' => 'database',
    |       'failure_threshold' => 3,
    |       'reset_timeout' => 30,
    |   ],
    |
    */

    'circuits' => [
        //
    ],

];
