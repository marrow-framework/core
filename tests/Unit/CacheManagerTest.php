<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Marrow\Cache\CacheManager;

// ── Tests ─────────────────────────────────────────────────────────────────────

beforeEach(function () {
    $this->cache = new CacheManager(sys_get_temp_dir(), 'array');
});

test('put and get round-trip a value', function () {
    $this->cache->put('greeting', 'hello');
    expect($this->cache->get('greeting'))->toBe('hello');
});

test('get returns the default when the key is missing', function () {
    expect($this->cache->get('missing', 'fallback'))->toBe('fallback');
});

test('has reflects presence correctly', function () {
    expect($this->cache->has('x'))->toBeFalse();
    $this->cache->put('x', 1);
    expect($this->cache->has('x'))->toBeTrue();
});

test('forget removes a key', function () {
    $this->cache->put('x', 1);
    $this->cache->forget('x');
    expect($this->cache->has('x'))->toBeFalse();
});

test('flush clears everything', function () {
    $this->cache->put('a', 1);
    $this->cache->put('b', 2);
    $this->cache->flush();
    expect($this->cache->has('a'))->toBeFalse();
    expect($this->cache->has('b'))->toBeFalse();
});

test('remember caches the callback result and only calls it once', function () {
    $calls = 0;
    $callback = function () use (&$calls) {
        $calls++;
        return 'computed';
    };

    expect($this->cache->remember('key', 3600, $callback))->toBe('computed');
    expect($this->cache->remember('key', 3600, $callback))->toBe('computed');
    expect($calls)->toBe(1);
});

test('increment accumulates across calls', function () {
    expect($this->cache->increment('counter'))->toBe(1);
    expect($this->cache->increment('counter'))->toBe(2);
    expect($this->cache->increment('counter', 5))->toBe(7);
});

test('forever stores without expiry (readable immediately)', function () {
    $this->cache->forever('permanent', 'value');
    expect($this->cache->get('permanent'))->toBe('value');
});

test('keys with PSR-6 reserved characters are sanitized transparently', function () {
    $this->cache->put('user:1/profile', 'ok');
    expect($this->cache->get('user:1/profile'))->toBe('ok');
});
