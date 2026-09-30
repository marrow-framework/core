<?php

declare(strict_types=1);

namespace Marrow\Support;

/**
 * Symmetric encryption helper backed by AES-256-GCM (authenticated).
 * The key is derived from APP_KEY via SHA-256, so any non-empty APP_KEY
 * works as input regardless of its raw length.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;

    /** @throws \RuntimeException if APP_KEY is not configured or encryption fails. */
    public static function encrypt(string $plaintext): string
    {
        $key = self::key();
        $iv = random_bytes(openssl_cipher_iv_length(self::CIPHER));
        $tag = '';

        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Returns '' if $encrypted is empty, malformed, or fails authentication
     * (tampered ciphertext / wrong key) — never returns garbage plaintext.
     *
     * @throws \RuntimeException if APP_KEY is not configured.
     */
    public static function decrypt(string $encrypted): string
    {
        if ($encrypted === '') {
            return '';
        }

        $decoded = base64_decode($encrypted, true);
        $ivLength = openssl_cipher_iv_length(self::CIPHER);

        if ($decoded === false || strlen($decoded) < $ivLength + self::TAG_LENGTH) {
            return '';
        }

        $iv = substr($decoded, 0, $ivLength);
        $tag = substr($decoded, $ivLength, self::TAG_LENGTH);
        $ciphertext = substr($decoded, $ivLength + self::TAG_LENGTH);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);

        return $plaintext === false ? '' : $plaintext;
    }

    /**
     * @throws \RuntimeException if APP_KEY is not configured — there is no
     *         safe fallback key for encrypting sensitive data. A prior
     *         version of this logic (duplicated in HasTwoFactor and
     *         Model's `encrypted` cast) silently stored plaintext instead,
     *         which is equivalent to not encrypting at all.
     */
    private static function key(): string
    {
        $appKey = $_ENV['APP_KEY'] ?? '';
        if ($appKey === '') {
            throw new \RuntimeException(
                'APP_KEY is not set — required for encryption. Generate one with: php forge key:generate'
            );
        }
        return hash('sha256', $appKey, true); // 32 raw bytes — correct key size for aes-256-gcm
    }
}
