<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Marrow\Http\Shield\ShieldConfig;

// ── Tests ─────────────────────────────────────────────────────────────────────

test('fromArray with empty config returns all defaults', function () {
    $shield = ShieldConfig::fromArray([]);

    expect($shield->headers)->toBe([]);
    expect($shield->hsts)->toBe(['max_age' => 31536000, 'include_subdomains' => true, 'preload' => false]);
    expect($shield->cspEnabled)->toBeFalse();
    expect($shield->cspPreset)->toBe('strict');
    expect($shield->cspDirectives)->toBe([]);
    expect($shield->cspReportOnly)->toBeFalse();
    expect($shield->csrfExcept)->toBe([]);
});

test('fromArray with partial config fills the rest with defaults', function () {
    $shield = ShieldConfig::fromArray([
        'csp' => ['enabled' => true, 'preset' => 'relaxed'],
        'csrf_except' => ['api/*'],
    ]);

    expect($shield->cspEnabled)->toBeTrue();
    expect($shield->cspPreset)->toBe('relaxed');
    expect($shield->cspDirectives)->toBe([]);
    expect($shield->csrfExcept)->toBe(['api/*']);
    // Untouched sections still default.
    expect($shield->headers)->toBe([]);
    expect($shield->hsts)->toBe(['max_age' => 31536000, 'include_subdomains' => true, 'preload' => false]);
});

test('fromArray honours fully custom values', function () {
    $shield = ShieldConfig::fromArray([
        'headers' => ['X-Frame-Options' => false],
        'hsts' => ['max_age' => 0],
        'csp' => [
            'enabled' => true,
            'preset' => 'strict',
            'directives' => ['script-src' => ['https://cdn.example.com']],
            'report_only' => true,
        ],
        'csrf_except' => ['webhooks/stripe'],
    ]);

    expect($shield->headers)->toBe(['X-Frame-Options' => false]);
    expect($shield->hsts)->toBe(['max_age' => 0]);
    expect($shield->cspDirectives)->toBe(['script-src' => ['https://cdn.example.com']]);
    expect($shield->cspReportOnly)->toBeTrue();
    expect($shield->csrfExcept)->toBe(['webhooks/stripe']);
});
