<?php

declare(strict_types=1);

namespace Marrow\Auth\Concerns;

/**
 * HasTwoFactor — TOTP-based two-factor authentication (RFC 6238).
 *
 * Requires three columns on the model's table:
 *   two_factor_secret          VARCHAR(64) NULL
 *   two_factor_recovery_codes  TEXT NULL
 *   two_factor_enabled_at      TIMESTAMP NULL
 *
 * Usage:
 *   $secret  = $user->enableTwoFactor();   // returns base32 secret to show in QR
 *   $user->save();
 *   $user->verifyTwoFactor('123456');      // verify an OTP code
 *   $user->disableTwoFactor();
 *   $codes = $user->generateRecoveryCodes(); // 8 single-use codes
 */
trait HasTwoFactor
{
    // ── Lifecycle ─────────────────────────────────────────────────────

    /**
     * Enable 2FA for this user.
     * Returns the plaintext base32 secret to display / encode as QR URI.
     * The secret is stored encrypted (AES-256-GCM, see encryptSecret()).
     */
    public function enableTwoFactor(): string
    {
        $secret = $this->generateBase32Secret();

        $this->two_factor_secret        = $this->encryptSecret($secret);
        $this->two_factor_recovery_codes = json_encode($this->generateRecoveryCodes());
        $this->two_factor_enabled_at    = date('Y-m-d H:i:s');

        return $secret;
    }

    public function disableTwoFactor(): void
    {
        $this->two_factor_secret         = null;
        $this->two_factor_recovery_codes = null;
        $this->two_factor_enabled_at     = null;
    }

    public function twoFactorEnabled(): bool
    {
        return !empty($this->two_factor_secret) && !empty($this->two_factor_enabled_at);
    }

    // ── Verification ──────────────────────────────────────────────────

    /**
     * Verify a 6-digit OTP code (window ±1 step = 90s tolerance).
     * Also checks recovery codes.
     */
    public function verifyTwoFactor(string $code): bool
    {
        $code = trim($code);

        // Try OTP first
        $secret = $this->decryptSecret((string) $this->two_factor_secret);
        if ($secret !== '' && $this->validateTotp($secret, $code)) {
            return true;
        }

        // Try recovery codes
        return $this->redeemRecoveryCode($code);
    }

    /**
     * Build a QR-code URI for authenticator apps.
     * Pass $appName (e.g. "MyApp") and $userLabel (e.g. user's email).
     */
    public function twoFactorQrUri(string $appName, string $userLabel): string
    {
        $secret = $this->decryptSecret((string) $this->two_factor_secret);
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            rawurlencode($appName),
            rawurlencode($userLabel),
            $secret,
            rawurlencode($appName)
        );
    }

    // ── Recovery codes ────────────────────────────────────────────────

    /** Generate 8 random recovery codes (format: XXXX-XXXX). */
    public function generateRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2)));
        }
        return $codes;
    }

    public function recoveryCodes(): array
    {
        return json_decode((string) $this->two_factor_recovery_codes, true) ?? [];
    }

    private function redeemRecoveryCode(string $code): bool
    {
        $codes = $this->recoveryCodes();
        $code  = strtoupper(trim($code));

        foreach ($codes as $i => $stored) {
            if (hash_equals($stored, $code)) {
                // Burn the code
                unset($codes[$i]);
                $this->two_factor_recovery_codes = json_encode(array_values($codes));
                return true;
            }
        }
        return false;
    }

    // ── TOTP (RFC 6238) ───────────────────────────────────────────────

    private function validateTotp(string $secret, string $code): bool
    {
        $code = str_pad($code, 6, '0', STR_PAD_LEFT);

        // Check window: t-1, t, t+1
        for ($offset = -1; $offset <= 1; $offset++) {
            if (hash_equals($this->computeTotp($secret, $offset), $code)) {
                return true;
            }
        }
        return false;
    }

    private function computeTotp(string $secret, int $windowOffset = 0): string
    {
        $time = intdiv((int) floor(time() / 30) + $windowOffset, 1);
        $key  = $this->base32Decode($secret);
        $msg  = pack('J', $time); // unsigned 64-bit big-endian

        $hash   = hash_hmac('sha1', $msg, $key, true);
        $offset = ord($hash[19]) & 0x0f;

        $code = (
            ((ord($hash[$offset])     & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) <<  8) |
            ((ord($hash[$offset + 3]) & 0xff))
        ) % 1_000_000;

        return str_pad((string) $code, 6, '0', STR_PAD_LEFT);
    }

    // ── Base32 ────────────────────────────────────────────────────────

    private function generateBase32Secret(int $length = 16): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bytes    = random_bytes($length);
        $secret   = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $alphabet[ord($bytes[$i]) & 31];
        }
        return $secret;
    }

    private function base32Decode(string $input): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $input    = strtoupper(rtrim($input, '='));
        $buffer   = 0;
        $bufLen   = 0;
        $output   = '';

        foreach (str_split($input) as $char) {
            $pos = strpos($alphabet, $char);
            if ($pos === false) {
                continue;
            }
            $buffer = ($buffer << 5) | $pos;
            $bufLen += 5;
            if ($bufLen >= 8) {
                $bufLen -= 8;
                $output .= chr(($buffer >> $bufLen) & 0xff);
            }
        }
        return $output;
    }

    // ── Secret encryption (AES-256-GCM, authenticated) ────────────────

    /**
     * Delegates to Marrow\Support\Crypto (shared AES-256-GCM helper, also
     * used by Model's `encrypted` cast). A random IV per call plus the GCM
     * auth tag are stored alongside the ciphertext, so tampering is
     * detected on decrypt (decryptSecret() returns '' rather than garbage
     * plaintext).
     */
    private function encryptSecret(string $secret): string
    {
        return \Marrow\Support\Crypto::encrypt($secret);
    }

    private function decryptSecret(string $encrypted): string
    {
        return \Marrow\Support\Crypto::decrypt($encrypted);
    }
}
