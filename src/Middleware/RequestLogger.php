<?php

declare(strict_types=1);

namespace Marrow\Middleware;

use Marrow\Database\Connection;
use Marrow\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs every HTTP request (method, URI, status, duration) via PSR-3.
 * In debug mode (`APP_DEBUG=true`) also appends an `X-Marrow-Profile` header:
 *
 *   X-Marrow-Profile: method=GET uri=/ status=200 time=12ms queries=3
 *
 * SQL query tracking requires the database Connection to have logging enabled
 * (Connection::enableQueryLog() called during bootstrap).
 */
class RequestLogger
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?Connection $db = null
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $start = hrtime(true);

        // Flush any pre-request queries so we only count queries from this request
        $this->db?->flushQueryLog();

        /** @var Response $response */
        $response = $next($request);

        $elapsed = (int) round((hrtime(true) - $start) / 1_000_000); // ns → ms
        $method  = $request->getMethod();
        $uri     = $request->getPathInfo();
        $status  = $response->getStatusCode();
        $queries = $this->db !== null ? count($this->db->getQueryLog()) : null;

        // PSR-3 log (level based on status code)
        $message = sprintf(
            '%s %s → %d (%dms)%s',
            $method,
            $uri,
            $status,
            $elapsed,
            $queries !== null ? " [{$queries} queries]" : ''
        );

        $level = match (true) {
            $status >= 500 => 'error',
            $status >= 400 => 'warning',
            default        => 'info',
        };

        $this->logger->$level($message, [
            'method'   => $method,
            'uri'      => $uri,
            'status'   => $status,
            'time_ms'  => $elapsed,
            'queries'  => $queries,
        ]);

        // Debug profile header
        if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
            $profile = "method={$method} uri={$uri} status={$status} time={$elapsed}ms";
            if ($queries !== null) {
                $profile .= " queries={$queries}";
            }
            $response->headers->set('X-Marrow-Profile', $profile);
        }

        return $response;
    }
}
