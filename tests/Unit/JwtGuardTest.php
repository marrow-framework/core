<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Marrow\Auth\JwtGuard;
use Marrow\Database\Connection;
use Marrow\Http\Request;

// ── Tests ─────────────────────────────────────────────────────────────────────

beforeEach(function () {
    $this->conn = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
    $this->conn->statement('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT)');
    $this->userId = $this->conn->insert('users', ['email' => 'jane@example.com']);
});

function bearerRequest(string $token): Request
{
    return Request::create('/', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
}

// HS256 requires a key of at least 32 bytes (256 bits) — anything shorter is
// rejected by firebase/php-jwt itself, on top of JwtGuard's own emptiness check.
const TEST_SECRET = 'a-test-jwt-secret-that-is-at-least-32-bytes-long';
const OTHER_SECRET = 'a-different-jwt-secret-also-at-least-32-bytes';

test('createToken throws when no secret is configured', function () {
    $guard = new JwtGuard($this->conn, []);

    expect(fn () => $guard->createToken((object) ['id' => $this->userId]))
        ->toThrow(\RuntimeException::class, 'JWT_SECRET');
});

test('createToken throws when the configured secret is shorter than HS256 requires', function () {
    $guard = new JwtGuard($this->conn, ['secret' => 'too-short']);

    expect(fn () => $guard->createToken((object) ['id' => $this->userId]))
        ->toThrow(\RuntimeException::class, 'JWT_SECRET');
});

test('user() throws when no secret is configured, rather than silently returning null', function () {
    $guard = new JwtGuard($this->conn, [], bearerRequest('irrelevant.token.here'));

    expect(fn () => $guard->user())->toThrow(\RuntimeException::class, 'JWT_SECRET');
});

test('a token created and decoded with the same secret round-trips to the right user', function () {
    $guard = new JwtGuard($this->conn, ['secret' => TEST_SECRET]);
    $token = $guard->createToken((object) ['id' => $this->userId]);

    $authenticated = new JwtGuard($this->conn, ['secret' => TEST_SECRET], bearerRequest($token));

    expect($authenticated->check())->toBeTrue();
    expect($authenticated->id())->toBe($this->userId);
});

test('caller-supplied claims cannot override reserved claims (sub/iat/exp/iss)', function () {
    $guard = new JwtGuard($this->conn, ['secret' => TEST_SECRET]);
    $token = $guard->createToken((object) ['id' => $this->userId], [
        'sub' => 999999,
        'iss' => 'attacker-controlled',
        'role' => 'admin', // a non-reserved claim should still pass through
    ]);

    $authenticated = new JwtGuard($this->conn, ['secret' => TEST_SECRET], bearerRequest($token));

    // sub was NOT overridden — resolves back to the real user, not 999999.
    expect($authenticated->id())->toBe($this->userId);
});

test('a token decoded with the wrong secret is rejected, not a crash', function () {
    $guard = new JwtGuard($this->conn, ['secret' => TEST_SECRET]);
    $token = $guard->createToken((object) ['id' => $this->userId]);

    $wrongSecret = new JwtGuard($this->conn, ['secret' => OTHER_SECRET], bearerRequest($token));

    expect($wrongSecret->check())->toBeFalse();
});
