<?php

declare(strict_types=1);

namespace Marrow\Auth;

use Marrow\Database\Connection;
use Marrow\Http\Request;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;

/**
 * JWT authentication guard (stateless, for APIs).
 * Reads the Bearer token from the Authorization header.
 */
class JwtGuard implements GuardInterface
{
    private ?object $user = null;
    private bool $resolved = false;

    public function __construct(
        private readonly Connection $db,
        private readonly array $config,
        private ?Request $request = null
    ) {
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function user(): ?object
    {
        if ($this->resolved) {
            return $this->user;
        }

        $this->resolved = true;
        $token = $this->request?->bearerToken();

        if ($token === null) {
            return null;
        }

        // Resolved outside the try/catch below: a missing/misconfigured secret
        // is a real deployment error and must surface as one, not be swallowed
        // into "unauthenticated" alongside genuine token failures.
        $secret = $this->secret();

        try {
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));

            $table = $this->config['table'] ?? 'users';
            $row = $this->db->selectOne("SELECT * FROM {$table} WHERE id = ?", [$decoded->sub]);

            $this->user = $row ? (object) $row : null;
        } catch (Throwable) {
            $this->user = null;
        }

        return $this->user;
    }

    public function id(): int|string|null
    {
        return $this->user()?->id;
    }

    public function attempt(array $credentials): bool
    {
        return false; // JWT guard uses createToken() directly
    }

    public function login(object $user): void
    {
        $this->user = $user;
    }

    public function logout(): void
    {
        $this->user = null;
    }

    public function createToken(object $user, array $claims = []): string
    {
        $secret = $this->secret();
        $ttl = (int) ($this->config['ttl'] ?? $_ENV['JWT_TTL'] ?? 3600);
        $now = time();

        // Reserved claims are applied last so they can never be overridden by
        // caller-supplied $claims (previously array_merge()'s argument order
        // let $claims silently clobber sub/exp/iat/iss).
        $payload = array_merge($claims, [
            'iss' => $_ENV['APP_URL'] ?? 'marrow',
            'sub' => $user->id,
            'iat' => $now,
            'exp' => $now + $ttl,
        ]);

        return JWT::encode($payload, $secret, 'HS256');
    }

    /**
     * @throws \RuntimeException if JWT_SECRET is not configured, or too short
     *         for HS256 (which requires a 256-bit / 32-byte key — firebase/php-jwt
     *         rejects anything shorter with a DomainException, which the catch
     *         in user() would otherwise silently swallow into "unauthenticated").
     */
    private function secret(): string
    {
        $secret = $this->config['secret'] ?? $_ENV['JWT_SECRET'] ?? '';
        if (strlen($secret) < 32) {
            throw new \RuntimeException(
                'JWT_SECRET is not set or too short (HS256 requires at least 32 bytes). '
                . 'Generate one with: php forge jwt:secret'
            );
        }
        return $secret;
    }
}
