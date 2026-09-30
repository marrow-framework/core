<?php

declare(strict_types=1);

namespace Marrow\Http\Shield;

/**
 * Typed configuration for Marrow's security bundle — security headers, CSP,
 * HSTS and CSRF exemptions — inspired by AdonisJS Shield: one config source
 * (config/shield.php), one typed object, injected by constructor into
 * SecurityHeaders and VerifyCsrfToken instead of each reading raw config
 * arrays ambiently.
 */
final class ShieldConfig
{
    /**
     * @param array<string, string|false|null> $headers Extra/override security headers.
     * @param array{max_age?: int, include_subdomains?: bool, preload?: bool} $hsts
     *        Set max_age to 0 to disable HSTS entirely.
     * @param string $cspPreset 'strict'|'relaxed'; any other value yields an
     *        empty base policy — only $cspDirectives applies.
     * @param array<string, string[]> $cspDirectives Merged onto the preset.
     * @param string[] $csrfExcept fnmatch patterns exempt from CSRF verification.
     */
    public function __construct(
        public readonly array $headers = [],
        public readonly array $hsts = ['max_age' => 31536000, 'include_subdomains' => true, 'preload' => false],
        public readonly bool $cspEnabled = false,
        public readonly string $cspPreset = 'strict',
        public readonly array $cspDirectives = [],
        public readonly bool $cspReportOnly = false,
        public readonly array $csrfExcept = [],
    ) {
    }

    /** Build from the raw config/shield.php array, filling in defaults for anything missing. */
    public static function fromArray(array $config): self
    {
        $defaults = new self();

        return new self(
            headers: (array) ($config['headers'] ?? $defaults->headers),
            hsts: (array) ($config['hsts'] ?? $defaults->hsts),
            cspEnabled: (bool) ($config['csp']['enabled'] ?? $defaults->cspEnabled),
            cspPreset: (string) ($config['csp']['preset'] ?? $defaults->cspPreset),
            cspDirectives: (array) ($config['csp']['directives'] ?? $defaults->cspDirectives),
            cspReportOnly: (bool) ($config['csp']['report_only'] ?? $defaults->cspReportOnly),
            csrfExcept: (array) ($config['csrf_except'] ?? $defaults->csrfExcept),
        );
    }
}
