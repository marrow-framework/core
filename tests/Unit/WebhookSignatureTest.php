<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Marrow\Http\Webhook\WebhookSignature;

// ── Tests ─────────────────────────────────────────────────────────────────────

test('check() verifies a bare hex signature', function () {
    $payload = '{"event":"charge.succeeded"}';
    $secret = 'whsec_test';
    $signature = WebhookSignature::sign($payload, $secret);

    expect(WebhookSignature::check($payload, $signature, $secret))->toBeTrue();
});

test('check() strips an "algo=" prefix before comparing (GitHub-style header)', function () {
    $payload = '{"event":"push"}';
    $secret = 'whsec_test';
    $signature = 'sha256=' . WebhookSignature::sign($payload, $secret);

    expect(WebhookSignature::check($payload, $signature, $secret))->toBeTrue();
});

test('check() rejects a tampered payload', function () {
    $secret = 'whsec_test';
    $signature = WebhookSignature::sign('original', $secret);

    expect(WebhookSignature::check('tampered', $signature, $secret))->toBeFalse();
});

test('check() rejects an empty signature rather than matching an empty HMAC', function () {
    expect(WebhookSignature::check('payload', '', 'secret'))->toBeFalse();
    expect(WebhookSignature::check('payload', 'sha256=', 'secret'))->toBeFalse();
});

test('checkTimestamped() verifies a Stripe-style "t=...,v1=..." header within tolerance', function () {
    $payload = '{"event":"charge.succeeded"}';
    $secret = 'whsec_test';
    $timestamp = time();
    $signature = WebhookSignature::sign("{$timestamp}.{$payload}", $secret);
    $header = "t={$timestamp},v1={$signature}";

    expect(WebhookSignature::checkTimestamped($header, $payload, $secret))->toBeTrue();
});

test('checkTimestamped() rejects a signature whose timestamp is outside the tolerance window', function () {
    $payload = '{"event":"charge.succeeded"}';
    $secret = 'whsec_test';
    $timestamp = time() - 600; // 10 minutes old
    $signature = WebhookSignature::sign("{$timestamp}.{$payload}", $secret);
    $header = "t={$timestamp},v1={$signature}";

    expect(WebhookSignature::checkTimestamped($header, $payload, $secret, toleranceSeconds: 300))->toBeFalse();
});

test('checkTimestamped() rejects a well-formed but wrong signature', function () {
    $payload = '{"event":"charge.succeeded"}';
    $timestamp = time();
    $header = "t={$timestamp},v1=" . str_repeat('a', 64);

    expect(WebhookSignature::checkTimestamped($header, $payload, 'whsec_test'))->toBeFalse();
});

test('checkTimestamped() rejects a header missing the timestamp or signature component', function () {
    expect(WebhookSignature::checkTimestamped('v1=abc', 'payload', 'secret'))->toBeFalse();
    expect(WebhookSignature::checkTimestamped('t=' . time(), 'payload', 'secret'))->toBeFalse();
});

test('checkTimestamped() can verify a non-default signature prefix (secret rotation: v0/v1)', function () {
    $payload = 'body';
    $secret = 'whsec_previous';
    $timestamp = time();
    $signature = WebhookSignature::sign("{$timestamp}.{$payload}", $secret);
    $header = "t={$timestamp},v0={$signature},v1=" . str_repeat('b', 64);

    expect(WebhookSignature::checkTimestamped($header, $payload, $secret, signaturePrefix: 'v0'))->toBeTrue();
});
