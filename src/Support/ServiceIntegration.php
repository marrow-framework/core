<?php

declare(strict_types=1);

namespace Marrow\Support;

use Marrow\Config\Repository as ConfigRepository;
use Marrow\Http\HttpClient;
use Marrow\Http\Request;
use Marrow\Http\Webhook\WebhookSignature;

/**
 * Base class for wrapping a single third-party service (a payment provider,
 * an external API, ...) as one container-bound class — the structural piece
 * `config/services.php`'s credential convention was missing a home for.
 *
 * A concrete integration declares its own `config('services.<key>')` block
 * and, if it calls out over HTTP, a base URI:
 *
 *   class StripeIntegration extends ServiceIntegration
 *   {
 *       protected static function key(): string { return 'stripe'; }
 *       protected function baseUri(): ?string { return 'https://api.stripe.com/v1/'; }
 *
 *       public function createCharge(array $payload): array
 *       {
 *           return $this->http()->post('charges', ['json' => $payload])->json();
 *       }
 *   }
 *
 * Bind it like any other service from the owning module's register() —
 * plain reflection autowiring resolves both constructor params (HttpClient,
 * ConfigRepository), nothing extra to wire up:
 *
 *   $this->container->singleton(StripeIntegration::class, StripeIntegration::class);
 *
 * This is deliberately a base *class*, not an interface + separate "provider"
 * registration mechanism — Marrow's existing `#[Module(providers: [...])]` /
 * Container::singleton() already is that mechanism (see docs/modules.md).
 * What was missing wasn't a way to bind a service, it was a convention for
 * what a *bound external service* looks like: where its config lives, how
 * it gets a correctly-configured HttpClient, and how it verifies its own
 * inbound webhooks — this class is that convention, shared by every future
 * integration package instead of each reinventing it.
 */
abstract class ServiceIntegration
{
    /** @var array<string, mixed> */
    private readonly array $config;

    public function __construct(private readonly HttpClient $http, ConfigRepository $config)
    {
        $this->config = (array) $config->get('services.' . static::key(), []);
    }

    /** The `services.<key>` block this integration reads — e.g. 'stripe'. */
    abstract protected static function key(): string;

    /** Override to point http() at the provider's API host. Defaults to config('services.<key>.base_uri'). */
    protected function baseUri(): ?string
    {
        $uri = $this->setting('base_uri');
        return is_string($uri) && $uri !== '' ? $uri : null;
    }

    /** Config key read as the bearer token by http() — override if a provider names it differently than 'secret'. */
    protected function tokenConfigKey(): string
    {
        return 'secret';
    }

    /** Reads a key out of this integration's own `services.<key>` config block. */
    protected function setting(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /** A pre-configured HttpClient: base_uri applied, and a bearer token applied if one is configured. */
    protected function http(): HttpClient
    {
        $http = $this->http;

        if ($uri = $this->baseUri()) {
            $http = $http->baseUri($uri);
        }

        $token = $this->setting($this->tokenConfigKey());
        if (is_string($token) && $token !== '') {
            $http = $http->withToken($token);
        }

        return $http;
    }

    /**
     * Verifies an inbound webhook request against this integration's own
     * config block — the same 'webhook_header'/'webhook_timestamped'/
     * 'webhook_tolerance' keys the `webhook` route middleware reads (see
     * VerifyWebhookSignature), so `$this->verifyWebhook($request)` alone is
     * enough once those are set in config('services.<key>'); $header/
     * $toleranceSeconds are only there to override config for a one-off call.
     */
    protected function verifyWebhook(Request $request, ?string $header = null, ?int $toleranceSeconds = null): bool
    {
        $secret = $this->setting('webhook_secret');
        if (!is_string($secret) || $secret === '') {
            return false;
        }

        $header ??= (string) $this->setting('webhook_header', 'X-Webhook-Signature');
        $signature = $request->headers->get($header);
        if ($signature === null) {
            return false;
        }

        $toleranceSeconds ??= $this->setting('webhook_timestamped', false)
            ? (int) $this->setting('webhook_tolerance', 300)
            : 0;

        return $toleranceSeconds > 0
            ? WebhookSignature::checkTimestamped($signature, $request->getContent(), $secret, $toleranceSeconds)
            : WebhookSignature::check($request->getContent(), $signature, $secret);
    }
}
