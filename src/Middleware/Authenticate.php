<?php

declare(strict_types=1);

namespace Marrow\Middleware;

use Marrow\Auth\AuthManager;
use Marrow\Exceptions\HttpException;
use Marrow\Http\Request;
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

    /** @throws HttpException 401 for a JSON request, 302 (redirect to login) otherwise. */
    public function handle(Request $request, callable $next, string $guard = 'session'): Response
    {
        if (!$this->auth->guard($guard)->check()) {
            if ($request->wantsJson()) {
                throw new HttpException(401, 'Unauthenticated.');
            }
            throw new HttpException(302, '');
        }

        return $next($request);
    }
}
