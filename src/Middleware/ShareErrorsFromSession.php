<?php

declare(strict_types=1);

namespace Marrow\Middleware;

use Marrow\Http\Request;
use Marrow\Session\SessionManager;
use Marrow\Template\Engine;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pulls the `_errors`/`_old_input` flashed by a failed validation redirect
 * out of the session and shares them as Twig globals for the next request.
 */
class ShareErrorsFromSession
{
    public function __construct(
        private readonly SessionManager $session,
        private readonly Engine $view
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $errors = $this->session->pull('_errors', []);
        $oldInput = $this->session->pull('_old_input', []);

        $this->view->shareGlobal('_errors', $errors);
        $this->view->shareGlobal('_old_input', $oldInput);

        return $next($request);
    }
}
