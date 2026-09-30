<?php

declare(strict_types=1);

namespace Marrow\Middleware;

use Marrow\Config\Repository as Config;
use Marrow\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CORS middleware — adds Access-Control-* headers to every response.
 *
 * Configure in config/cors.php or via CORS_* env vars.
 *
 * Usage (middleware.php):
 *   'aliases' => [
 *       'cors' => HandleCors::class,
 *   ],
 *   'groups' => [
 *       'api' => ['cors', 'throttle'],
 *   ],
 *
 * Preflight OPTIONS requests are responded to immediately (no further pipeline).
 */
class HandleCors
{
    private array $config;

    public function __construct(Config $config)
    {
        $this->config = $this->loadConfig($config);
    }

    public function handle(Request $request, callable $next): Response
    {
        // Short-circuit OPTIONS preflight
        if ($request->isMethod('OPTIONS')) {
            return $this->preflight($request);
        }

        /** @var Response $response */
        $response = $next($request);
        $this->addHeaders($request, $response);

        return $response;
    }

    // ── Header helpers ────────────────────────────────────────────────

    private function preflight(Request $request): Response
    {
        $response = new Response('', 204);
        $this->addHeaders($request, $response);
        $response->headers->set('Content-Length', '0');
        return $response;
    }

    private function addHeaders(Request $request, Response $response): void
    {
        $origin = $request->headers->get('Origin', '');

        $response->headers->set(
            'Access-Control-Allow-Origin',
            $this->resolveOrigin((string) $origin)
        );

        $response->headers->set(
            'Access-Control-Allow-Methods',
            implode(', ', $this->config['allowed_methods'])
        );

        $response->headers->set(
            'Access-Control-Allow-Headers',
            implode(', ', $this->config['allowed_headers'])
        );

        if (!empty($this->config['exposed_headers'])) {
            $response->headers->set(
                'Access-Control-Expose-Headers',
                implode(', ', $this->config['exposed_headers'])
            );
        }

        if ($this->config['supports_credentials']) {
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }

        if ($this->config['max_age'] > 0) {
            $response->headers->set('Access-Control-Max-Age', (string) $this->config['max_age']);
        }

        // Vary header for caching proxies
        $vary = $response->headers->get('Vary', '');
        $response->headers->set('Vary', $vary ? $vary . ', Origin' : 'Origin');
    }

    private function resolveOrigin(string $requestOrigin): string
    {
        $allowed = $this->config['allowed_origins'];

        if (in_array('*', $allowed, true)) {
            return '*';
        }

        if ($requestOrigin !== '' && in_array($requestOrigin, $allowed, true)) {
            return $requestOrigin;
        }

        // Check wildcard patterns (e.g. *.example.com)
        foreach ($allowed as $pattern) {
            if (fnmatch($pattern, $requestOrigin)) {
                return $requestOrigin;
            }
        }

        // Return first configured origin as fallback
        return $allowed[0] ?? '';
    }

    // ── Config loading ────────────────────────────────────────────────

    private function loadConfig(Config $config): array
    {
        $defaults = [
            'allowed_origins'    => [($_ENV['CORS_ORIGINS'] ?? '*')],
            'allowed_methods'    => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
            'allowed_headers'    => ['Content-Type', 'Authorization', 'X-Requested-With', 'Accept', 'X-CSRF-TOKEN'],
            'exposed_headers'    => ['X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-Total-Count'],
            'supports_credentials' => false,
            'max_age'            => 86400,
        ];

        return array_merge($defaults, (array) $config->get('cors', []));
    }
}
