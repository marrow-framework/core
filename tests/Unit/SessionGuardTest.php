<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Marrow\Auth\Hash;
use Marrow\Auth\SessionGuard;
use Marrow\Cache\CacheManager;
use Marrow\Database\Connection;
use Marrow\Exceptions\HttpException;
use Marrow\RateLimiting\RateLimiter;
use Marrow\Session\SessionManager;

// ── Tests ─────────────────────────────────────────────────────────────────────
//
// attempt()'s failure paths (nonexistent user / wrong password) are exercised
// here without needing a started PHP session, since neither path calls
// login()/regenerate(). Both must still run a real Hash::verify() even when
// no user row was found, so response time doesn't leak account existence —
// see SessionGuard::DUMMY_HASH.

beforeEach(function () {
    $this->conn = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
    $this->conn->statement('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, password TEXT)');
    $this->conn->insert('users', ['email' => 'jane@example.com', 'password' => Hash::make('correct-password')]);

    $this->guard = new SessionGuard(new SessionManager(), $this->conn, []);
});

test('attempt fails for a nonexistent user without throwing', function () {
    $result = $this->guard->attempt(['email' => 'nobody@example.com', 'password' => 'whatever']);
    expect($result)->toBeFalse();
});

test('attempt fails for an existing user with the wrong password', function () {
    $result = $this->guard->attempt(['email' => 'jane@example.com', 'password' => 'wrong-password']);
    expect($result)->toBeFalse();
});

// ── Login throttling ──────────────────────────────────────────────────────────
//
// Without a RateLimiter (the two tests above), attempt() never locks out —
// confirms the feature is opt-in and doesn't regress guards built without a
// cache backend. These tests wire a real RateLimiter (in-memory ArrayAdapter,
// "great for tests" per CacheManager's own docblock) to exercise the actual
// lockout path.

beforeEach(function () {
    $this->limiter = new RateLimiter(new CacheManager(sys_get_temp_dir(), 'array'));
    $this->throttledGuard = new SessionGuard(
        new SessionManager(),
        $this->conn,
        ['throttle' => ['max_attempts' => 3, 'decay_seconds' => 60]],
        $this->limiter
    );
});

test('a correct password still succeeds below the failure threshold', function () {
    // max_attempts is 3 — two prior failures haven't tripped the lockout yet.
    for ($i = 0; $i < 2; $i++) {
        $this->throttledGuard->attempt(['email' => 'jane@example.com', 'password' => 'wrong']);
    }

    $result = $this->throttledGuard->attempt(['email' => 'jane@example.com', 'password' => 'correct-password']);
    expect($result)->toBeTrue();
});

test('attempt locks out an identifier after max_attempts failures and throws 429', function () {
    for ($i = 0; $i < 3; $i++) {
        $this->throttledGuard->attempt(['email' => 'jane@example.com', 'password' => 'wrong']);
    }

    expect(fn() => $this->throttledGuard->attempt(['email' => 'jane@example.com', 'password' => 'correct-password']))
        ->toThrow(HttpException::class);
});

test('lockout does not affect a different identifier', function () {
    $this->conn->insert('users', ['email' => 'john@example.com', 'password' => Hash::make('other-password')]);

    for ($i = 0; $i < 3; $i++) {
        $this->throttledGuard->attempt(['email' => 'jane@example.com', 'password' => 'wrong']);
    }

    $result = $this->throttledGuard->attempt(['email' => 'john@example.com', 'password' => 'other-password']);
    expect($result)->toBeTrue();
});

test('a successful login clears the failure counter', function () {
    $this->throttledGuard->attempt(['email' => 'jane@example.com', 'password' => 'wrong']);
    $this->throttledGuard->attempt(['email' => 'jane@example.com', 'password' => 'wrong']);
    $this->throttledGuard->attempt(['email' => 'jane@example.com', 'password' => 'correct-password']);

    // Counter reset by the success above — two more failures shouldn't lock out yet.
    $this->throttledGuard->attempt(['email' => 'jane@example.com', 'password' => 'wrong']);
    $result = $this->throttledGuard->attempt(['email' => 'jane@example.com', 'password' => 'wrong']);
    expect($result)->toBeFalse();
});
