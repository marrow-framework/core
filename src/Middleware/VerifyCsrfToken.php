<?php

declare(strict_types=1);

namespace Marrow\Middleware;

use Marrow\Exceptions\HttpException;
use Marrow\Http\Request;
use Marrow\Http\Shield\ShieldConfig;
use Marrow\Session\SessionManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the CSRF token on state-changing requests — part of Marrow's
 * Shield bundle (see ShieldConfig), inspired by AdonisJS Shield.
 *
 * Exempt URIs can be declared in two ways:
 *   1. Override the $except property in a subclass.
 *   2. Add patterns to config/shield.php → 'csrf_except' array.
 *
 * Patterns support fnmatch wildcards: 'api/*', 'webhooks/stripe'.
 */
class VerifyCsrfToken
{
    /** URIs exempt from CSRF verification (subclass to extend). */
    protected array $except = [];

    public function __construct(
        private readonly SessionManager $session,
        private readonly ShieldConfig $shield
    ) {
    }

    /** @throws HttpException 419 if the request is state-changing, not exempt, and the token doesn't match. */
    public function handle(Request $request, callable $next): Response
    {
        if ($this->isReading($request) || $this->inExceptArray($request) || $this->tokensMatch($request)) {
            return $next($request);
        }

        throw new HttpException(419, 'CSRF token mismatch.');
    }

    private function isReading(Request $request): bool
    {
        return in_array($request->getMethod(), ['HEAD', 'GET', 'OPTIONS'], true);
    }

    private function inExceptArray(Request $request): bool
    {
        $except = array_merge($this->except, $this->shield->csrfExcept);

        foreach ($except as $pattern) {
            if (fnmatch($pattern, $request->getPathInfo())) {
                return true;
            }
        }

        return false;
    }

    private function tokensMatch(Request $request): bool
    {
        $token = $request->request->get('_token')
            ?? $request->headers->get('X-CSRF-TOKEN')
            ?? $request->headers->get('X-XSRF-TOKEN');

        $sessionToken = $this->session->csrfToken();

        return $token !== null && hash_equals($sessionToken, (string) $token);
    }
}
