<?php

declare(strict_types=1);

namespace Marrow\Cache;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;

/**
 * PSR-6 backed cache manager wrapping symfony/cache.
 *
 * Drivers (config/cache.php → 'default'):
 *   file   → FilesystemAdapter (storage/cache/app)
 *   apcu   → ApcuAdapter
 *   redis  → RedisAdapter (cache.redis.dsn)
 *   array  → ArrayAdapter (in-memory, per-request — great for tests)
 *
 * The public API is intentionally simple (get/put/remember) and stable;
 * the underlying adapter can change without touching call sites.
 */
class CacheManager
{
    private AdapterInterface $pool;

    public function __construct(
        private readonly string $path,
        private readonly string $driver = 'file',
        private readonly array $config = []
    ) {
        $this->pool = $this->makeAdapter();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $item = $this->pool->getItem($this->sanitize($key));
        return $item->isHit() ? $item->get() : $default;
    }

    public function put(string $key, mixed $value, int $ttl = 3600): void
    {
        $item = $this->pool->getItem($this->sanitize($key));
        $item->set($value);
        if ($ttl > 0) {
            $item->expiresAfter($ttl);
        }
        $this->pool->save($item);
    }

    public function has(string $key): bool
    {
        return $this->pool->hasItem($this->sanitize($key));
    }

    public function forget(string $key): void
    {
        $this->pool->deleteItem($this->sanitize($key));
    }

    public function flush(): void
    {
        $this->pool->clear();
    }

    /**
     * Increment a counter, returning the new value.
     *
     * Note: this is NOT atomic — it is a read-modify-write and can race
     * under concurrency. The $ttl only applies when the item is first
     * created; an existing item's expiry is preserved.
     */
    public function increment(string $key, int $by = 1, int $ttl = 0): int
    {
        $item = $this->pool->getItem($this->sanitize($key));
        $new = ((int) ($item->isHit() ? $item->get() : 0)) + $by;
        if ($ttl > 0 && !$item->isHit()) {
            $item->expiresAfter($ttl);
        }
        $item->set($new);
        $this->pool->save($item);
        return $new;
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $sanitized = $this->sanitize($key);
        $item = $this->pool->getItem($sanitized);

        if ($item->isHit()) {
            return $item->get();
        }

        $value = $callback();
        $item->set($value);
        if ($ttl > 0) {
            $item->expiresAfter($ttl);
        }
        $this->pool->save($item);

        return $value;
    }

    /** Store forever (no expiry). */
    public function forever(string $key, mixed $value): void
    {
        $this->put($key, $value, 0);
    }

    /** Underlying PSR-6 pool, for advanced use or framework wiring. */
    public function pool(): CacheItemPoolInterface
    {
        return $this->pool;
    }

    private function makeAdapter(): AdapterInterface
    {
        return match ($this->driver) {
            'apcu'  => new ApcuAdapter('marrow'),
            'array' => new ArrayAdapter(),
            'redis' => new RedisAdapter(
                RedisAdapter::createConnection($this->config['redis']['dsn'] ?? 'redis://localhost')
            ),
            default => new FilesystemAdapter('marrow', 0, $this->path),
        };
    }

    private function sanitize(string $key): string
    {
        // PSR-6 reserves {}()/\@: — replace them so any key is valid.
        return preg_replace('/[{}()\/\\\\@:]/', '.', $key) ?? $key;
    }
}
