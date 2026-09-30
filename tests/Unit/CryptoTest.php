<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Marrow\Support\Crypto;

// ── Tests ─────────────────────────────────────────────────────────────────────
//
// Crypto is the shared AES-256-GCM helper behind HasTwoFactor's secret
// encryption and Model's `encrypted` cast. A prior version of both call
// sites used unauthenticated AES-256-CBC and silently fell back to storing
// plaintext when APP_KEY was empty.

beforeEach(function () {
    $this->previousAppKey = $_ENV['APP_KEY'] ?? null;
    $_ENV['APP_KEY'] = 'a-test-app-key-for-crypto';
});

afterEach(function () {
    if ($this->previousAppKey === null) {
        unset($_ENV['APP_KEY']);
    } else {
        $_ENV['APP_KEY'] = $this->previousAppKey;
    }
});

test('encrypt then decrypt round-trips the original plaintext', function () {
    $encrypted = Crypto::encrypt('sensitive-value');
    expect($encrypted)->not->toBe('sensitive-value');
    expect(Crypto::decrypt($encrypted))->toBe('sensitive-value');
});

test('decrypting a tampered payload fails closed instead of returning garbage', function () {
    $encrypted = Crypto::encrypt('sensitive-value');
    $tampered = $encrypted;
    $tampered[5] = $tampered[5] === 'A' ? 'B' : 'A';

    expect(Crypto::decrypt($tampered))->toBe('');
});

test('decrypting an empty string returns an empty string', function () {
    expect(Crypto::decrypt(''))->toBe('');
});

test('encrypt() throws when APP_KEY is not configured, rather than storing plaintext', function () {
    unset($_ENV['APP_KEY']);
    expect(fn () => Crypto::encrypt('sensitive-value'))->toThrow(\RuntimeException::class, 'APP_KEY');
});

test('decrypt() throws when APP_KEY is not configured', function () {
    $encrypted = Crypto::encrypt('sensitive-value');
    unset($_ENV['APP_KEY']);
    expect(fn () => Crypto::decrypt($encrypted))->toThrow(\RuntimeException::class, 'APP_KEY');
});
