<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Marrow\Auth\Hash;
use Marrow\Auth\SessionGuard;
use Marrow\Database\Connection;
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
