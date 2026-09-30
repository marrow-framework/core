<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Marrow\Http\Request;
use Marrow\Http\Shield\ShieldConfig;
use Marrow\Middleware\SecurityHeaders;
use Symfony\Component\HttpFoundation\Response;

// ── Tests ─────────────────────────────────────────────────────────────────────

function dispatchThrough(SecurityHeaders $middleware, Request $request): Response
{
    return $middleware->handle($request, fn (Request $req) => new Response('ok'));
}

test('default security headers are applied', function () {
    $middleware = new SecurityHeaders(new ShieldConfig());
    $response = dispatchThrough($middleware, Request::create('/'));

    expect($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN');
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
});

test('a header set to false omits the default', function () {
    $shield = new ShieldConfig(headers: ['X-Frame-Options' => false]);
    $middleware = new SecurityHeaders($shield);
    $response = dispatchThrough($middleware, Request::create('/'));

    expect($response->headers->has('X-Frame-Options'))->toBeFalse();
});

test('HSTS is only added on secure (HTTPS) requests', function () {
    $middleware = new SecurityHeaders(new ShieldConfig());

    $insecure = dispatchThrough($middleware, Request::create('/', 'GET', [], [], [], ['HTTPS' => '']));
    expect($insecure->headers->has('Strict-Transport-Security'))->toBeFalse();

    $secure = dispatchThrough($middleware, Request::create('/', 'GET', [], [], [], ['HTTPS' => 'on']));
    expect($secure->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000; includeSubDomains');
});

test('CSP header is only added when enabled', function () {
    $disabled = new SecurityHeaders(new ShieldConfig());
    $response = dispatchThrough($disabled, Request::create('/', 'GET', [], [], [], ['HTTPS' => 'on']));
    expect($response->headers->has('Content-Security-Policy'))->toBeFalse();

    $enabled = new SecurityHeaders(new ShieldConfig(cspEnabled: true, cspPreset: 'strict'));
    $response = dispatchThrough($enabled, Request::create('/'));
    expect($response->headers->get('Content-Security-Policy'))->toContain("default-src 'self'");
});

test('CSP report_only uses the Report-Only header instead', function () {
    $shield = new ShieldConfig(cspEnabled: true, cspPreset: 'strict', cspReportOnly: true);
    $middleware = new SecurityHeaders($shield);
    $response = dispatchThrough($middleware, Request::create('/'));

    expect($response->headers->has('Content-Security-Policy'))->toBeFalse();
    expect($response->headers->get('Content-Security-Policy-Report-Only'))->toContain("default-src 'self'");
});
