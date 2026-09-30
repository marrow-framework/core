<?php

declare(strict_types=1);

namespace Marrow\Auth;

/**
 * Password hashing using Argon2id.
 */
class Hash
{
    private const OPTIONS = ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1];

    public static function make(string $password): string
    {
        // password_hash() returns string in PHP 8+ (never false — throws Error on failure).
        return password_hash($password, PASSWORD_ARGON2ID, self::OPTIONS);
    }

    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, self::OPTIONS);
    }
}
