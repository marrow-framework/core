<?php

declare(strict_types=1);

namespace Marrow\Middleware;

use Marrow\Auth\AuthManager;
use Marrow\Exceptions\HttpException;
use Marrow\Http\Request;
use Marrow\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects the request unless the given guard (default: `session`) has an
 * authenticated user.
 */
class Authenticate
{
    public function __construct(private readonly AuthManager $auth)
    {
    }

    /** @throws HttpException 401 for a JSON request. */
    public function handle(Request $request, callable $next, string $guard = 'session'): Response
    {
        if (!$this->auth->guard($guard)->check()) {
            if ($request->wantsJson()) {
                throw new HttpException(401, 'Unauthenticated.');
            }

            // A real RedirectResponse, not `throw new HttpException(302, '')`:
            // the Handler's non-JSON path always builds a fresh error-page
            // Response for an HttpException (there is no `302.html.twig`, and
            // nothing before this fix ever copied getHeaders() — so no
            // 'Location' — onto that response either), so it never actually
            // redirected anywhere. RedirectIfAuthenticated (the `guest`
            // middleware) already avoids this by returning a RedirectResponse
            // directly instead of throwing; this mirrors that.
            return new RedirectResponse((string) config('auth.redirects.login', '/login'));
        }

        return $next($request);
    }
}
