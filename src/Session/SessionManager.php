<?php

declare(strict_types=1);

namespace Marrow\Session;

use Marrow\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;

/**
 * Wraps Symfony Session. Provides get/put/flash/pull/csrf helpers.
 * The session cookie is HttpOnly, SameSite=Lax, and Secure in production.
 */
class SessionManager
{
    private Session $session;
    private bool $started = false;

    public function __construct()
    {
        // Same local/dev detection as Http\Kernel::isDevMode() and
        // Middleware\HotReloadMiddleware::isDevMode() — Secure is on by
        // default and only dropped for local development.
        $env   = strtolower((string) ($_ENV['APP_ENV'] ?? 'production'));
        $debug = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $secure = !($env === 'local' || $debug);

        $storage = new NativeSessionStorage([
            'cookie_httponly' => true,
            'cookie_samesite' => 'lax',
            'cookie_secure'   => $secure,
        ]);
        $this->session = new Session($storage);
    }

    public function start(Request $request): void
    {
        if ($this->started) {
            return;
        }
        $this->session->start();
        $this->started = true;

        // Ensure CSRF token exists
        if (!$this->session->has('_csrf_token')) {
            $this->session->set('_csrf_token', bin2hex(random_bytes(32)));
        }
    }

    public function save(Response $response): void
    {
        $this->session->save();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        // Support dot notation for nested flash data
        if (str_contains($key, '.')) {
            [$parent, $child] = explode('.', $key, 2);
            $parent = $this->session->get($parent, []);
            return is_array($parent) ? ($parent[$child] ?? $default) : $default;
        }
        return $this->session->get($key, $default);
    }

    public function put(string $key, mixed $value): void
    {
        $this->session->set($key, $value);
    }

    public function has(string $key): bool
    {
        return $this->session->has($key);
    }

    public function forget(string $key): void
    {
        $this->session->remove($key);
    }

    public function flash(string $key, mixed $value): void
    {
        $this->session->getFlashBag()->add($key, $value);
        // Also store directly so pull() works
        $this->session->set($key, $value);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->session->get($key, $default);
        $this->session->remove($key);
        return $value;
    }

    public function regenerate(): void
    {
        $this->session->migrate(true);
        $this->session->set('_csrf_token', bin2hex(random_bytes(32)));
    }

    /** @throws \RuntimeException If called before start() has run. */
    public function csrfToken(): string
    {
        if (!$this->started) {
            // Failing loud here matters: a silent '' would make an empty
            // submitted _token pass hash_equals('', '') in VerifyCsrfToken —
            // a real CSRF bypass if the pipeline ever runs without
            // StartSession ahead of it, rather than a visible misconfiguration.
            throw new \RuntimeException(
                'Session has not been started — csrfToken() requires StartSession to run first.'
            );
        }
        if (!$this->session->has('_csrf_token')) {
            $this->session->set('_csrf_token', bin2hex(random_bytes(32)));
        }
        return (string) $this->session->get('_csrf_token');
    }

    public function all(): array
    {
        return $this->session->all();
    }

    public function getId(): string
    {
        return $this->session->getId();
    }
}
