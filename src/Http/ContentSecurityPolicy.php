<?php

declare(strict_types=1);

namespace Marrow\Http;

/**
 * Fluent Content-Security-Policy builder.
 *
 * Usage:
 *   $csp = ContentSecurityPolicy::strict()
 *       ->allow('script-src', ['https://cdn.example.com'])
 *       ->allow('img-src', ['data:', 'https:']);
 *
 *   header('Content-Security-Policy: ' . $csp->compile());
 *
 * Or from config/middleware.php → 'csp' => [...] (see SecurityHeaders).
 */
class ContentSecurityPolicy
{
    /** @var array<string, string[]> */
    private array $directives = [];

    public function __construct(array $directives = [])
    {
        $this->directives = $directives;
    }

    /** A locked-down baseline: only same-origin, no inline/eval. */
    public static function strict(): self
    {
        return new self([
            'default-src'     => ["'self'"],
            'script-src'      => ["'self'"],
            'style-src'       => ["'self'"],
            'img-src'         => ["'self'", 'data:'],
            'font-src'        => ["'self'"],
            'connect-src'     => ["'self'"],
            'frame-ancestors' => ["'none'"],
            'base-uri'        => ["'self'"],
            'form-action'     => ["'self'"],
            'object-src'      => ["'none'"],
        ]);
    }

    /** A relaxed baseline suitable for apps using inline scripts/CDNs. */
    public static function relaxed(): self
    {
        return new self([
            'default-src' => ["'self'"],
            'script-src'  => ["'self'", "'unsafe-inline'"],
            'style-src'   => ["'self'", "'unsafe-inline'"],
            'img-src'     => ["'self'", 'data:', 'https:'],
            'font-src'    => ["'self'", 'data:'],
        ]);
    }

    /** Append sources to a directive (creates it if absent). */
    public function allow(string $directive, array $sources): self
    {
        $this->directives[$directive] = array_values(array_unique(
            array_merge($this->directives[$directive] ?? [], $sources)
        ));
        return $this;
    }

    /** Replace a directive's sources entirely. */
    public function set(string $directive, array $sources): self
    {
        $this->directives[$directive] = $sources;
        return $this;
    }

    public function remove(string $directive): self
    {
        unset($this->directives[$directive]);
        return $this;
    }

    /** Add a per-request nonce to script-src and style-src. */
    public function withNonce(string $nonce): self
    {
        $this->allow('script-src', ["'nonce-{$nonce}'"]);
        $this->allow('style-src', ["'nonce-{$nonce}'"]);
        return $this;
    }

    public function compile(): string
    {
        $parts = [];
        foreach ($this->directives as $directive => $sources) {
            $parts[] = empty($sources)
                ? $directive
                : $directive . ' ' . implode(' ', $sources);
        }
        return implode('; ', $parts);
    }

    public function __toString(): string
    {
        return $this->compile();
    }
}
