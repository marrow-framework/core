<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Marrow\Tests\Unit\Fixtures\TwoFactorUserStub;

// ── Tests ─────────────────────────────────────────────────────────────────────
//
// Exercises HasTwoFactor's AES-256-GCM secret encryption (replacing the prior
// static-key XOR scheme) and RFC 6238 TOTP verification.

beforeEach(function () {
    $this->previousAppKey = $_ENV['APP_KEY'] ?? null;
    $_ENV['APP_KEY'] = 'a-test-app-key-for-2fa-encryption';
});

afterEach(function () {
    if ($this->previousAppKey === null) {
        unset($_ENV['APP_KEY']);
    } else {
        $_ENV['APP_KEY'] = $this->previousAppKey;
    }
});

/** Independent RFC 6238 reference implementation — mirrors HasTwoFactor::computeTotp(). */
function totpCodeFor(string $base32Secret, int $offset = 0): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $input    = strtoupper(rtrim($base32Secret, '='));
    $buffer   = 0;
    $bufLen   = 0;
    $key      = '';

    foreach (str_split($input) as $char) {
        $pos = strpos($alphabet, $char);
        if ($pos === false) {
            continue;
        }
        $buffer = ($buffer << 5) | $pos;
        $bufLen += 5;
        if ($bufLen >= 8) {
            $bufLen -= 8;
            $key .= chr(($buffer >> $bufLen) & 0xff);
        }
    }

    $time = (int) floor(time() / 30) + $offset;
    $msg  = pack('J', $time);
    $hash = hash_hmac('sha1', $msg, $key, true);
    $off  = ord($hash[19]) & 0x0f;

    $code = (
        ((ord($hash[$off])     & 0x7f) << 24) |
        ((ord($hash[$off + 1]) & 0xff) << 16) |
        ((ord($hash[$off + 2]) & 0xff) <<  8) |
        ((ord($hash[$off + 3]) & 0xff))
    ) % 1_000_000;

    return str_pad((string) $code, 6, '0', STR_PAD_LEFT);
}

test('a valid TOTP code for the enabled secret verifies successfully', function () {
    $user   = new TwoFactorUserStub();
    $secret = $user->enableTwoFactor();

    expect($user->verifyTwoFactor(totpCodeFor($secret)))->toBeTrue();
});

test('an incorrect TOTP code is rejected', function () {
    $user = new TwoFactorUserStub();
    $user->enableTwoFactor();

    expect($user->verifyTwoFactor('000000'))->toBeFalse();
});

test('a recovery code can be redeemed exactly once', function () {
    $user  = new TwoFactorUserStub();
    $user->enableTwoFactor();
    $codes = $user->recoveryCodes();
    $code  = $codes[0];

    expect($user->verifyTwoFactor($code))->toBeTrue();
    expect($user->verifyTwoFactor($code))->toBeFalse(); // burned
});

test('a tampered stored secret fails closed instead of decrypting to garbage', function () {
    $user = new TwoFactorUserStub();
    $secret = $user->enableTwoFactor();

    // Flip a character in the stored ciphertext (GCM auth tag must now fail).
    $tampered = $user->two_factor_secret;
    $tampered[10] = $tampered[10] === 'A' ? 'B' : 'A';
    $user->two_factor_secret = $tampered;

    expect($user->verifyTwoFactor(totpCodeFor($secret)))->toBeFalse();
});

test('enabling 2FA without APP_KEY configured fails loudly instead of using a hardcoded fallback key', function () {
    unset($_ENV['APP_KEY']);
    $user = new TwoFactorUserStub();

    expect(fn () => $user->enableTwoFactor())->toThrow(\RuntimeException::class, 'APP_KEY');
});
