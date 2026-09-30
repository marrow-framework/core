<?php

declare(strict_types=1);

namespace Marrow\Middleware;

use Marrow\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SanitizeInput — strips null bytes and enforces string length limits.
 *
 * This is a defense-in-depth measure. It does NOT HTML-encode values
 * (that's the template engine's job). What it does:
 *
 *   1. Removes null bytes (prevents log injection and some SQL quirks)
 *   2. Truncates strings beyond MAX_STRING_LENGTH (DoS prevention)
 *   3. Skips excluded fields (passwords, binary uploads, etc.)
 *
 * Twig auto-escapes all output, making stored-XSS a non-issue in templates.
 */
class SanitizeInput
{
    private const MAX_STRING_LENGTH = 65_535;

    private const SKIP_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        '_token',
    ];

    public function handle(Request $request, callable $next): Response
    {
        $this->sanitizeBag($request->request);
        $this->sanitizeBag($request->query);

        return $next($request);
    }

    // ── Bag sanitization ──────────────────────────────────────────────

    private function sanitizeBag(\Symfony\Component\HttpFoundation\ParameterBag $bag): void
    {
        $cleaned = [];
        foreach ($bag->all() as $key => $value) {
            $cleaned[$key] = $this->sanitizeValue((string) $key, $value);
        }
        $bag->replace($cleaned);
    }

    private function sanitizeValue(string $key, mixed $value): mixed
    {
        if (in_array($key, self::SKIP_KEYS, true)) {
            return $value;
        }

        if (is_array($value)) {
            return array_map(
                fn($v) => $this->sanitizeValue($key, $v),
                $value
            );
        }

        if (!is_string($value)) {
            return $value;
        }

        // Strip null bytes
        $value = str_replace("\0", '', $value);

        // Truncate oversized strings
        if (mb_strlen($value) > self::MAX_STRING_LENGTH) {
            $value = mb_substr($value, 0, self::MAX_STRING_LENGTH);
        }

        return $value;
    }
}
