<?php

declare(strict_types=1);

namespace Marrow\Middleware;

use Marrow\Http\Request;
use Marrow\Http\RedirectResponse;
use Marrow\Session\SessionManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Starts the session before the request is handled, flashes any pending
 * RedirectResponse data into it, and persists it (writing the session
 * cookie) once the response comes back.
 */
class StartSession
{
    public function __construct(private readonly SessionManager $session)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $this->session->start($request);

        $response = $next($request);

        // Flash redirect data into session
        if ($response instanceof RedirectResponse) {
            foreach ($response->getFlashData() as $key => $value) {
                $this->session->flash($key, $value);
            }
        }

        $this->session->save($response);

        return $response;
    }
}
