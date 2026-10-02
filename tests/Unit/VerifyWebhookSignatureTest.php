<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Marrow\Config\Repository as ConfigRepository;
use Marrow\Exceptions\HttpException;
use Marrow\Http\Request;
use Marrow\Http\Response;
use Marrow\Http\Webhook\WebhookSignature;
use Marrow\Middleware\VerifyWebhookSignature;

// ── Tests ─────────────────────────────────────────────────────────────────────
//
// The route middleware (->middleware('webhook:<name>')) behind
// docs/integrations.md. $name is always a config('services.<name>') lookup
// key, never a literal secret. ConfigRepository is injected via the
// constructor (same convention as VerifyCsrfToken/ShieldConfig) rather than
// read through the global config() helper, so these tests build a plain
// in-memory ConfigRepository directly instead of booting a real Application
// singleton — which would leak across test files in the same process (see
// HandlerTest's makeHandler() docblock for the same hazard).

function configuredWith(array $services): ConfigRepository
{
    $config = new ConfigRepository();
    $config->set('services', $services);
    return $config;
}

test('rejects the request with a 400 when the named service has no webhook_secret configured', function () {
    $middleware = new VerifyWebhookSignature(configuredWith(['stripe' => []]));
    $request = Request::create('/webhooks/stripe', 'POST');

    expect(fn () => $middleware->handle($request, fn ($r) => new Response('ok'), 'stripe'))
        ->toThrow(HttpException::class);
});

test('rejects the request with a 400 when the signature header is missing', function () {
    $middleware = new VerifyWebhookSignature(configuredWith(['stripe' => ['webhook_secret' => 'whsec_test']]));
    $request = Request::create('/webhooks/stripe', 'POST');

    expect(fn () => $middleware->handle($request, fn ($r) => new Response('ok'), 'stripe'))
        ->toThrow(HttpException::class);
});

test('passes through a correctly signed request (single-header scheme)', function () {
    $secret = 'whsec_test';
    $payload = '{"event":"charge.succeeded"}';
    $middleware = new VerifyWebhookSignature(configuredWith(['stripe' => ['webhook_secret' => $secret]]));

    $signature = WebhookSignature::sign($payload, $secret);
    $request = Request::create('/webhooks/stripe', 'POST', content: $payload);
    $request->headers->set('X-Webhook-Signature', $signature);

    $response = $middleware->handle($request, fn ($r) => new Response('handled'), 'stripe');

    expect($response->getContent())->toBe('handled');
});

test('rejects a request with an incorrect signature', function () {
    $secret = 'whsec_test';
    $payload = '{"event":"charge.succeeded"}';
    $middleware = new VerifyWebhookSignature(configuredWith(['stripe' => ['webhook_secret' => $secret]]));

    $request = Request::create('/webhooks/stripe', 'POST', content: $payload);
    $request->headers->set('X-Webhook-Signature', 'not-the-right-signature');

    expect(fn () => $middleware->handle($request, fn ($r) => new Response('ok'), 'stripe'))
        ->toThrow(HttpException::class);
});

test('honors a custom header name and the timestamped scheme', function () {
    $secret = 'whsec_test';
    $payload = '{"event":"charge.succeeded"}';
    $middleware = new VerifyWebhookSignature(configuredWith(['stripe' => [
        'webhook_secret' => $secret,
        'webhook_header' => 'Stripe-Signature',
        'webhook_timestamped' => true,
    ]]));

    $timestamp = time();
    $signature = WebhookSignature::sign("{$timestamp}.{$payload}", $secret);
    $request = Request::create('/webhooks/stripe', 'POST', content: $payload);
    $request->headers->set('Stripe-Signature', "t={$timestamp},v1={$signature}");

    $response = $middleware->handle($request, fn ($r) => new Response('handled'), 'stripe');

    expect($response->getContent())->toBe('handled');
});
