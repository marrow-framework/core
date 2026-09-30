<?php

declare(strict_types=1);

namespace Marrow\Middleware;

use Marrow\Auth\AuthManager;
use Marrow\Http\Request;
use Marrow\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inverse of Authenticate: redirects to `/` if the given guard (default:
 * `session`) already has an authenticated user — for guest-only routes
 * like the login form.
 */
class RedirectIfAuthenticated
{
    public function __construct(private readonly AuthManager $auth)
    {
    }

    public function handle(Request $request, callable $next, string $guard = 'session'): Response
    {
        if ($this->auth->guard($guard)->check()) {
            return new RedirectResponse('/');
        }
        return $next($request);
    }
}
