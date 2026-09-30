# Cache

`Marrow\Cache\CacheManager` is a PSR-6-backed cache wrapper over
`symfony/cache`, configured entirely from `config/cache.php`.

## Drivers

| Driver | Adapter | Notes |
|---|---|---|
| `file` (default) | `FilesystemAdapter` | `storage/cache/app` |
| `apcu` | `ApcuAdapter` | requires the `apcu` PHP extension |
| `redis` | `RedisAdapter` | DSN from `cache.redis.dsn` |
| `array` | `ArrayAdapter` | in-memory, resets every process — ideal for tests |

```php
// config/cache.php
return [
    'default' => env('CACHE_DRIVER', 'file'),
    'redis' => ['dsn' => env('REDIS_DSN', 'redis://127.0.0.1:6379')],
];
```

## Usage

```php
$cache = app(\Marrow\Cache\CacheManager::class);

$cache->put('key', $value, ttl: 3600);
$cache->get('key', 'default');
$cache->has('key');
$cache->forget('key');
$cache->forever('key', $value);            // no expiry
$cache->flush();                           // clears everything

$cache->remember('expensive-report', 600, function () {
    return ExpensiveReport::generate();
});

$cache->increment('page-views', by: 1, ttl: 0);  // read-modify-write, NOT atomic
```

`increment()` is a plain read-then-write and can race under concurrency —
don't rely on it for anything that must be exact under load (use a database
counter with a real atomic increment for that instead).

### The `cache()` helper

```php
cache();                          // the CacheManager instance
cache('key');                     // get
cache('key', $value, 3600);       // put (3rd arg optional, default 3600s)
```

## Rate limiting is built on the cache

`RateLimiting\RateLimiter` (used by the `throttle` middleware) stores its
sliding-window hit timestamps through `CacheManager` — see
[Security Hardening](security.md#rate-limiting) for the middleware-facing
API.

## Testing

Set `CACHE_DRIVER=array` in your test environment (or construct a
`CacheManager` directly with `driver: 'array'`) so cached values never leak
between test runs and no filesystem/Redis dependency is needed.
