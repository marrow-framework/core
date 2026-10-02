<?php

declare(strict_types=1);

namespace Marrow\Http\Webhook;

/**
 * Provider-agnostic HMAC verification for inbound webhooks — the primitive
 * every "Stripe sends a POST, verify it's really Stripe" integration needs
 * and would otherwise hand-roll with a bare `hash_hmac`/`hash_equals` call.
 *
 * Two schemes are covered, matching the two conventions providers actually
 * use in practice:
 *
 *   - A single signature header, optionally prefixed with its algorithm
 *     (GitHub's `X-Hub-Signature-256: sha256=<hex>`, or a bare hex digest):
 *       WebhookSignature::check($request->getContent(), $header, $secret);
 *
 *   - A timestamped header carrying both a Unix timestamp and a signature
 *     over "{timestamp}.{payload}" (Stripe's `Stripe-Signature: t=...,v1=...`),
 *     which also rejects replays outside a tolerance window:
 *       WebhookSignature::checkTimestamped($header, $payload, $secret);
 *
 * Always verify against the *raw* request body (`$request->getContent()`),
 * never a re-encoded `json_encode($request->request->all())` — whitespace or
 * key-order differences from the original bytes the provider signed will
 * make a genuine webhook fail verification.
 */
final class WebhookSignature
{
    /** Hex-encoded HMAC of $payload under $secret. */
    public static function sign(string $payload, string $secret, string $algo = 'sha256'): string
    {
        return hash_hmac($algo, $payload, $secret);
    }

    /**
     * Verifies a single-value signature header against $payload.
     *
     * $signature may be a bare hex digest or carry an "algo=" prefix (e.g.
     * "sha256=abcdef...") — the prefix, if present, is stripped before
     * comparison; it does not override $algo.
     */
    public static function check(string $payload, string $signature, string $secret, string $algo = 'sha256'): bool
    {
        if (str_contains($signature, '=')) {
            [, $signature] = explode('=', $signature, 2);
        }

        if ($signature === '') {
            return false;
        }

        return hash_equals(self::sign($payload, $secret, $algo), $signature);
    }

    /**
     * Verifies a "t=<timestamp>,v1=<signature>"-style header (Stripe's
     * scheme): the signature covers "{timestamp}.{payload}", and the
     * timestamp must fall within $toleranceSeconds of now — rejecting both
     * a tampered payload and a captured-and-replayed old request.
     *
     * $signaturePrefix selects which "vN=" component to verify when a
     * provider sends multiple (Stripe includes both a current and, during
     * secret rotation, a previous signature under different prefixes).
     */
    public static function checkTimestamped(
        string $header,
        string $payload,
        string $secret,
        int $toleranceSeconds = 300,
        string $algo = 'sha256',
        string $signaturePrefix = 'v1',
    ): bool {
        $parts = [];
        foreach (explode(',', $header) as $pair) {
            $pair = trim($pair);
            if (!str_contains($pair, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $pair, 2);
            $parts[trim($key)] = trim($value);
        }

        $timestamp = $parts['t'] ?? null;
        $signature = $parts[$signaturePrefix] ?? null;

        if ($timestamp === null || $signature === null || !ctype_digit($timestamp)) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > $toleranceSeconds) {
            return false;
        }

        $expected = self::sign("{$timestamp}.{$payload}", $secret, $algo);

        return hash_equals($expected, $signature);
    }
}
