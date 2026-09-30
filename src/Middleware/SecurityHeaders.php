<?php

declare(strict_types=1);

namespace Marrow\Middleware;

use Marrow\Http\ContentSecurityPolicy;
use Marrow\Http\Request;
use Marrow\Http\Shield\ShieldConfig;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds common security headers to every response — part of Marrow's Shield
 * bundle (see ShieldConfig), inspired by AdonisJS Shield.
 *
 * Configurable via config/shield.php → 'headers' (associative array of
 * header => value; set value to false to omit a default).
 *
 * Content-Security-Policy is built separately via the 'csp' config key:
 *   'csp' => [
 *       'enabled' => true,
 *       'preset'  => 'strict',          // 'strict' | 'relaxed' | null
 *       'directives' => [               // merged onto the preset
 *           'script-src' => ['https://cdn.example.com'],
 *       ],
 *       'report_only' => false,
 *   ]
 *
 * HSTS is added automatically on HTTPS requests (configurable via 'hsts').
 */
class SecurityHeaders
{
    private const DEFAULTS = [
        'X-Frame-Options'        => 'SAMEORIGIN',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy'        => 'strict-origin-when-cross-origin',
        'Permissions-Policy'     => 'camera=(), microphone=(), geolocation=()',
        'Cross-Origin-Opener-Policy'   => 'same-origin',
        'Cross-Origin-Resource-Policy' => 'same-origin',
    ];

    public function __construct(private readonly ShieldConfig $shield)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);

        // Static headers
        $headers = array_merge(self::DEFAULTS, $this->shield->headers);
        foreach ($headers as $name => $value) {
            if ($value === false || $value === null) {
                continue;
            }
            $response->headers->set($name, (string) $value);
        }

        // HSTS — only meaningful over HTTPS
        if ($request->isSecure()) {
            $hsts = $this->shield->hsts;
            if (($hsts['max_age'] ?? 0) > 0) {
                $value = 'max-age=' . (int) $hsts['max_age'];
                if (!empty($hsts['include_subdomains'])) {
                    $value .= '; includeSubDomains';
                }
                if (!empty($hsts['preload'])) {
                    $value .= '; preload';
                }
                $response->headers->set('Strict-Transport-Security', $value);
            }
        }

        // Content-Security-Policy
        $this->applyCsp($response);

        return $response;
    }

    private function applyCsp(Response $response): void
    {
        if (!$this->shield->cspEnabled) {
            return;
        }

        $policy = match ($this->shield->cspPreset) {
            'relaxed' => ContentSecurityPolicy::relaxed(),
            'strict'  => ContentSecurityPolicy::strict(),
            default   => new ContentSecurityPolicy(),
        };

        foreach ($this->shield->cspDirectives as $directive => $sources) {
            $policy->allow($directive, (array) $sources);
        }

        $header = $this->shield->cspReportOnly
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';

        $response->headers->set($header, $policy->compile());
    }
}
