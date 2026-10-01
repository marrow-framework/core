<?php

declare(strict_types=1);

namespace Marrow\Auth;

use Marrow\Database\Connection;
use Marrow\Exceptions\HttpException;
use Marrow\RateLimiting\RateLimiter;
use Marrow\Session\SessionManager;

/**
 * Session-based authentication guard.
 * Stores the authenticated user id in the session.
 */
class SessionGuard implements GuardInterface
{
    /**
     * A valid Argon2id hash of an arbitrary fixed string. Used so attempt()
     * always runs a real password_verify() of comparable cost, even when no
     * user row was found — otherwise the response time would leak whether a
     * given username/email exists (a classic account-enumeration side channel).
     */
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$a0cyUHdPYzN6ektjbkp1bg$g39gGl9xBHhPsiEataMzCttOwJU/6j5fuNfZrl0kyp4';

    private const DEFAULT_MAX_ATTEMPTS = 5;
    private const DEFAULT_DECAY_SECONDS = 60;

    private ?object $user = null;

    /**
     * $limiter is nullable (and optional) so existing callers that construct
     * SessionGuard directly — tests, mainly — keep working without a cache
     * backend. Without it, attempt() simply skips lockout and behaves exactly
     * as before.
     */
    public function __construct(
        private readonly SessionManager $session,
        private readonly Connection $db,
        private readonly array $config,
        private readonly ?RateLimiter $limiter = null
    ) {
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function user(): ?object
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $id = $this->session->get('auth_user_id');
        if ($id === null) {
            return null;
        }

        $table = $this->config['table'] ?? 'users';
        $row = $this->db->selectOne("SELECT * FROM {$table} WHERE id = ?", [$id]);

        if ($row === null) {
            $this->session->forget('auth_user_id');
            return null;
        }

        $this->user = (object) $row;
        return $this->user;
    }

    public function id(): int|string|null
    {
        return $this->session->get('auth_user_id');
    }

    /**
     * @throws HttpException 429 when the account has too many recent failed
     *         attempts (see throttleKey()) — a credential-stuffing / brute-force
     *         lockout independent of (and in addition to) any route-level
     *         `throttle:` middleware, since that one is keyed by IP and won't
     *         catch a single account attacked from many addresses.
     */
    public function attempt(array $credentials): bool
    {
        $table = $this->config['table'] ?? 'users';
        $username = $this->config['username'] ?? 'email';
        $identifier = (string) ($credentials[$username] ?? '');
        $throttleKey = $this->throttleKey($identifier);

        if ($this->limiter !== null
            && $this->limiter->tooManyAttempts($throttleKey, $this->maxAttempts(), $this->decaySeconds())
        ) {
            $retryAfter = $this->limiter->availableIn($throttleKey, $this->maxAttempts(), $this->decaySeconds());
            throw (new HttpException(429, "Trop de tentatives de connexion. Réessayez dans {$retryAfter} secondes."))
                ->withHeaders(['Retry-After' => (string) $retryAfter]);
        }

        $row = $this->db->selectOne(
            "SELECT * FROM {$table} WHERE {$username} = ?",
            [$identifier]
        );

        // Always run a real Argon2id verification, even when no row was found,
        // so response time doesn't reveal whether the account exists.
        $hash  = $row['password'] ?? self::DUMMY_HASH;
        $valid = Hash::verify($credentials['password'] ?? '', $hash);

        if ($row === null || !$valid) {
            // Only failed attempts count towards the lockout — a user who
            // always types the right password is never throttled.
            $this->limiter?->hit($throttleKey, $this->decaySeconds());
            return false;
        }

        $this->limiter?->clear($throttleKey);
        $this->login((object) $row);
        return true;
    }

    public function login(object $user): void
    {
        $this->session->put('auth_user_id', $user->id);
        $this->session->regenerate();
        $this->user = $user;
    }

    public function logout(): void
    {
        $this->session->forget('auth_user_id');
        $this->session->regenerate();
        $this->user = null;
    }

    /**
     * Keyed by the login identifier alone (not IP): the goal is to protect
     * one specific account against credential stuffing, which is commonly
     * distributed across many source IPs and would otherwise sail straight
     * past an IP-keyed limiter.
     */
    private function throttleKey(string $identifier): string
    {
        return 'login:' . mb_strtolower($identifier);
    }

    private function maxAttempts(): int
    {
        return (int) ($this->config['throttle']['max_attempts'] ?? self::DEFAULT_MAX_ATTEMPTS);
    }

    private function decaySeconds(): int
    {
        return (int) ($this->config['throttle']['decay_seconds'] ?? self::DEFAULT_DECAY_SECONDS);
    }
}
