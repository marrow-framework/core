<?php

declare(strict_types=1);

namespace Marrow\Middleware;

use Marrow\Application;
use Marrow\Exceptions\HttpException;
use Marrow\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks every request with a 503 while storage/maintenance.flag exists
 * (written by the `down` command), unless the request carries the flag's
 * bypass secret in a `maintenance_bypass` cookie.
 */
class MaintenanceMode
{
    public function __construct(private readonly Application $app)
    {
    }

    /** @throws HttpException 503, unless the request holds a valid bypass cookie. */
    public function handle(Request $request, callable $next): Response
    {
        $flag = $this->app->path('storage', 'maintenance.flag');

        if (!is_file($flag)) {
            return $next($request);
        }

        // Allow bypass via a secret cookie
        $secret = trim((string) file_get_contents($flag));
        if ($secret && $request->cookies->get('maintenance_bypass') === $secret) {
            return $next($request);
        }

        throw new HttpException(503, 'Service Unavailable — maintenance mode.');
    }
}
