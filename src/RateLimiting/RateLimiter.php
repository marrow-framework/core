<?php

declare(strict_types=1);

namespace Marrow\RateLimiting;

use Marrow\Cache\CacheManager;

/**
 * Sliding-window rate limiter backed by the cache pool.
 *
 * Unlike a fixed-window counter (which lets a client send 2× the limit across
 * a window boundary), this tracks individual hit timestamps and counts only
 * those inside the trailing window — smooth and abuse-resistant.
 *
 * Usage:
 *   if ($limiter->tooManyAttempts('login:'.$ip, 5, 60)) { ... }
 *   $limiter->hit('login:'.$ip, 60);
 *   $limiter->clear('login:'.$ip);   // on success
 */
class RateLimiter
{
    public function __construct(private readonly CacheManager $cache)
    {
    }

    /**
     * Record a hit and return the current count within the window.
     *
     * @param int $decaySeconds Length of the sliding window in seconds.
     */
    public function hit(string $key, int $decaySeconds): int
    {
        $now = microtime(true);
        $cutoff = $now - $decaySeconds;

        /** @var float[] $timestamps */
        $timestamps = $this->cache->get($this->key($key), []);
        $timestamps = array_values(array_filter($timestamps, static fn ($t) => $t > $cutoff));
        $timestamps[] = $now;

        $this->cache->put($this->key($key), $timestamps, $decaySeconds + 1);

        return count($timestamps);
    }

    /** Number of hits currently inside the window. */
    public function attempts(string $key, int $decaySeconds): int
    {
        $cutoff = microtime(true) - $decaySeconds;
        $timestamps = $this->cache->get($this->key($key), []);
        return count(array_filter($timestamps, static fn ($t) => $t > $cutoff));
    }

    public function tooManyAttempts(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        return $this->attempts($key, $decaySeconds) >= $maxAttempts;
    }

    public function remaining(string $key, int $maxAttempts, int $decaySeconds): int
    {
        return max(0, $maxAttempts - $this->attempts($key, $decaySeconds));
    }

    /**
     * Seconds until enough hits fall out of the window for the client to try
     * again (i.e. when the count drops below $maxAttempts). Returns 0 when not
     * currently limited.
     */
    public function availableIn(string $key, int $maxAttempts, int $decaySeconds): int
    {
        $cutoff = microtime(true) - $decaySeconds;
        $timestamps = $this->cache->get($this->key($key), []);
        $timestamps = array_values(array_filter($timestamps, static fn ($t) => $t > $cutoff));
        $count = count($timestamps);
        if ($count < $maxAttempts) {
            return 0;
        }
        sort($timestamps);
        $slot = $timestamps[$count - $maxAttempts];
        return (int) max(0, ceil(($slot + $decaySeconds) - microtime(true)));
    }

    public function clear(string $key): void
    {
        $this->cache->forget($this->key($key));
    }

    /**
     * Run the callback only if under the limit; returns null when throttled.
     */
    public function attempt(string $key, int $maxAttempts, int $decaySeconds, callable $callback): mixed
    {
        if ($this->tooManyAttempts($key, $maxAttempts, $decaySeconds)) {
            return null;
        }
        $this->hit($key, $decaySeconds);
        return $callback();
    }

    private function key(string $key): string
    {
        return 'ratelimit.' . sha1($key);
    }
}
