# Laravel Circuit Breaker

A small, framework-native implementation of the **circuit breaker** pattern for Laravel.

When your app talks to external or internal services (payment gateways, search, internal microservices), a slow or failing dependency can cascade into your own app: piling up requests, exhausting workers, and increasing latency. A circuit breaker watches failures and, once a threshold is crossed, "opens" the circuit to **fail fast** (or fall back) instead of hammering a broken dependency. After a cooldown it lets a few trial requests through to see whether the dependency has recovered.

## States

```
CLOSED  ──(failures >= failure_threshold)──▶  OPEN
  ▲                                            │
  │                                  (reset_timeout elapsed)
  │                                            ▼
  └──(successes >= success_threshold)──   HALF-OPEN ──(any failure)──▶ OPEN
```

- **Closed** – calls pass through; failures are counted within a rolling `sample_window`.
- **Open** – calls fail fast (throw `CircuitOpenException` or run a fallback) until `reset_timeout` elapses.
- **Half-open** – a limited number of trial calls are allowed; enough successes close the circuit, any failure reopens it.

## Requirements

- PHP 8.2+
- Laravel 10, 11, or 12

## Installation

```bash
composer require nikola/laravel-circuit-breaker
```

The service provider and `CircuitBreaker` facade are auto-discovered.

Publish the config:

```bash
php artisan vendor:publish --tag=circuit-breaker-config
```

If you intend to use the `database` store, run the migrations (the package migration is loaded automatically, or publish it):

```bash
php artisan migrate
# optionally: php artisan vendor:publish --tag=circuit-breaker-migrations
```

## Configuration

`config/circuit-breaker.php`:

```php
return [
    'default' => env('CIRCUIT_BREAKER_STORE', 'redis'),

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

    'defaults' => [
        'failure_threshold' => 5,  // failures (within sample_window) before opening
        'success_threshold' => 2,  // half-open successes before closing
        'reset_timeout'     => 60, // seconds open before a half-open trial
        'sample_window'     => 60, // seconds failures are counted over
        'handle'            => [\Throwable::class], // which exceptions count as failures
    ],

    'circuits' => [
        'payments' => [
            'store' => 'database',
            'failure_threshold' => 3,
            'reset_timeout' => 30,
        ],
    ],
];
```

## Usage

### Protecting a Laravel HTTP call

```php
use Illuminate\Support\Facades\Http;
use Nikola\CircuitBreaker\Facades\CircuitBreaker;

$response = CircuitBreaker::for('payments')->call(
    fn () => Http::throw()->post('https://api.provider.com/charge', $payload),
);
```

If `payments` is open, the closure is never executed and a `CircuitOpenException` is thrown immediately.

### With a fallback

The fallback receives the throwable that caused the rejection/failure and its return value is returned to the caller:

```php
$rates = CircuitBreaker::for('fx-rates')->call(
    fn () => Http::throw()->get('https://fx.example.com/latest')->json(),
    fn ($e) => Cache::get('fx-rates:last-known', []), // graceful degradation
);
```

### Short proxy form

```php
$result = CircuitBreaker::call('search', fn () => $client->search($query));
```

### Inspecting / resetting

```php
$breaker = CircuitBreaker::for('payments');

$breaker->state();        // State::Closed | State::Open | State::HalfOpen
$breaker->isAvailable();  // false while open and within reset_timeout
$breaker->reset();        // force back to closed
```

## Events

Lifecycle transitions dispatch events you can listen to (e.g. for logging or alerting):

- `Nikola\CircuitBreaker\Events\CircuitOpened`
- `Nikola\CircuitBreaker\Events\CircuitHalfOpened`
- `Nikola\CircuitBreaker\Events\CircuitClosed`

Each carries the circuit name via `$event->circuit`.

```php
Event::listen(CircuitOpened::class, function (CircuitOpened $event) {
    Log::warning("Circuit opened: {$event->circuit}");
});
```

## Stores

| Driver     | Notes                                                                 |
|------------|-----------------------------------------------------------------------|
| `redis`    | Default. Atomic counters with TTL-based rolling window. Recommended for multi-server deployments. |
| `database` | Persists state in the `circuit_breakers` table. Good when Redis isn't available. |

### Custom drivers

```php
use Nikola\CircuitBreaker\Facades\CircuitBreaker;

CircuitBreaker::extend('apcu', function (array $config, $container) {
    return new \App\CircuitBreaker\ApcuStore($config['prefix'] ?? 'cb:');
});
```

Implement `Nikola\CircuitBreaker\Contracts\Store` for any backend.

## Testing

```bash
composer install
vendor/bin/phpunit
```

The database suite runs against in-memory SQLite. The Redis suite is skipped automatically when no Redis server is reachable (configure via `REDIS_HOST`/`REDIS_PORT`/`REDIS_TEST_DB`).

## License

MIT
