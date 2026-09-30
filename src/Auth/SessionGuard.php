<?php

declare(strict_types=1);

namespace Marrow\Auth;

use Marrow\Database\Connection;
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

    private ?object $user = null;

    public function __construct(
        private readonly SessionManager $session,
        private readonly Connection $db,
        private readonly array $config
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

    public function attempt(array $credentials): bool
    {
        $table = $this->config['table'] ?? 'users';
        $username = $this->config['username'] ?? 'email';

        $row = $this->db->selectOne(
            "SELECT * FROM {$table} WHERE {$username} = ?",
            [$credentials[$username] ?? '']
        );

        // Always run a real Argon2id verification, even when no row was found,
        // so response time doesn't reveal whether the account exists.
        $hash  = $row['password'] ?? self::DUMMY_HASH;
        $valid = Hash::verify($credentials['password'] ?? '', $hash);

        if ($row === null || !$valid) {
            return false;
        }

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
}
