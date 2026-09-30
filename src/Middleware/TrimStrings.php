<?php

declare(strict_types=1);

namespace Marrow\Middleware;

use Marrow\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trims leading/trailing whitespace from every string input field, except
 * password-like fields listed in $except.
 */
class TrimStrings
{
    private array $except = ['password', 'password_confirmation', 'current_password'];

    public function handle(Request $request, callable $next): Response
    {
        foreach ($request->request->all() as $key => $value) {
            if (!in_array($key, $this->except, true) && is_string($value)) {
                $request->request->set($key, trim($value));
            }
        }
        return $next($request);
    }
}
