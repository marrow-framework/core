<?php

declare(strict_types=1);

namespace Marrow\Middleware;

use Marrow\Config\Repository as ConfigRepository;
use Marrow\Exceptions\HttpException;
use Marrow\Http\Request;
use Marrow\Http\Webhook\WebhookSignature;
use Marrow\Http\Response;

/**
 * Rejects an inbound webhook request unless it carries a valid HMAC
 * signature, read from `config('services.<name>')` — the counterpart to
 * `config/services.php`'s existing credential convention.
 *
 * Usage: ->middleware('webhook:stripe') reads config('services.stripe'):
 *
 *   'stripe' => [
 *       'webhook_secret'    => env('STRIPE_WEBHOOK_SECRET'),
 *       'webhook_header'    => 'Stripe-Signature',      // default: 'X-Webhook-Signature'
 *       'webhook_timestamped' => true,                  // default: false (single-value header)
 *       'webhook_tolerance' => 300,                      // seconds, timestamped scheme only
 *   ],
 *
 * The route's $name parameter is a config key, never a literal secret — a
 * secret in the route/middleware declaration itself would leak into route
 * listings and stack traces the way a hardcoded throttle limit wouldn't.
 *
 * Deliberately a 400, not a 401/403: an invalid signature here almost always
 * means a misconfigured secret or a provider sending a legitimate but
 * unverifiable request, not a credentialed-but-unauthorized caller.
 */
class VerifyWebhookSignature
{
    public function __construct(private readonly ConfigRepository $config)
    {
    }

    /** @throws HttpException 400 if $name has no configured secret, or the signature doesn't verify. */
    public function handle(Request $request, callable $next, string $name): Response
    {
        $config = (array) $this->config->get("services.{$name}", []);
        $secret = $config['webhook_secret'] ?? null;

        if (!is_string($secret) || $secret === '') {
            throw new HttpException(400, "No webhook secret configured for 'services.{$name}.webhook_secret'.");
        }

        $header = $request->headers->get($config['webhook_header'] ?? 'X-Webhook-Signature');

        if ($header === null) {
            throw new HttpException(400, 'Missing webhook signature header.');
        }

        $verified = ($config['webhook_timestamped'] ?? false)
            ? WebhookSignature::checkTimestamped($header, $request->getContent(), $secret, (int) ($config['webhook_tolerance'] ?? 300))
            : WebhookSignature::check($request->getContent(), $header, $secret);

        if (!$verified) {
            throw new HttpException(400, 'Invalid webhook signature.');
        }

        return $next($request);
    }
}
