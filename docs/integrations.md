# External Service Integrations

Three primitives cover the groundwork every payment-provider/external-API
integration ends up needing, before any dedicated package (a Stripe wrapper,
say) is built on top of them.

## `config/services.php`

The conventional home for third-party credentials — nothing in the framework
reads this file automatically except the `http` key (default options for the
shared `Http\HttpClient` service); everything else is read by convention by
the two primitives below, one service block at a time:

```php
// config/services.php
'stripe' => [
    'base_uri'            => 'https://api.stripe.com/v1/',
    'secret'              => env('STRIPE_SECRET'),
    'webhook_secret'      => env('STRIPE_WEBHOOK_SECRET'),
    'webhook_header'      => 'Stripe-Signature',
    'webhook_timestamped' => true,
],
```

## `Support\ServiceIntegration`

A base class for wrapping one external service as a single, container-bound
class — not a new DI mechanism (modules' `#[Module(providers: [...])]` /
`Container::singleton()` already do that, see [Modules](modules.md)), but a
shared convention for what a *bound external service* looks like: where its
config lives, how it gets a correctly-configured `HttpClient`, and how it
verifies its own inbound webhooks.

```php
use Marrow\Support\ServiceIntegration;

class StripeIntegration extends ServiceIntegration
{
    protected static function key(): string
    {
        return 'stripe'; // reads config('services.stripe')
    }

    public function createCharge(array $payload): array
    {
        // http() already carries base_uri + a Bearer token from config('services.stripe.secret')
        return $this->http()->post('charges', ['json' => $payload])->json();
    }

    public function handleWebhook(Request $request): void
    {
        // Reads 'webhook_header'/'webhook_timestamped'/'webhook_tolerance'
        // straight from config('services.stripe') — no need to repeat them here.
        if (!$this->verifyWebhook($request)) {
            abort(400, 'Invalid Stripe signature.');
        }
        // ... process $request->getContent()
    }
}
```

Bind it from the owning module's `register()` — plain reflection autowiring
resolves the `HttpClient` constructor parameter, nothing extra to wire up:

```php
public function register(): void
{
    $this->container->singleton(StripeIntegration::class, StripeIntegration::class);
}
```

Override `baseUri()` directly instead of relying on `config('services.<key>.base_uri')`
when a provider's host isn't meant to be configurable. Override
`tokenConfigKey()` if a provider's credential isn't named `secret` in its
config block (Stripe calls it a "secret key", but another provider might
call it `api_key` or `token`).

## Webhook signature verification

`Http\Webhook\WebhookSignature` is the provider-agnostic HMAC primitive
behind both `ServiceIntegration::verifyWebhook()` and the `webhook` route
middleware below — use it directly for a one-off check:

```php
use Marrow\Http\Webhook\WebhookSignature;

// A single signature header, bare or "algo=" prefixed (GitHub's
// X-Hub-Signature-256: sha256=<hex>):
WebhookSignature::check($request->getContent(), $header, $secret);

// A timestamped header carrying "t=<unix>,v1=<hex>" (Stripe's scheme) —
// also rejects a captured-and-replayed request outside the tolerance window:
WebhookSignature::checkTimestamped($header, $request->getContent(), $secret, toleranceSeconds: 300);
```

Always verify against the **raw** request body
(`$request->getContent()`) — never a re-encoded
`json_encode($request->request->all())`, which can differ byte-for-byte from
what the provider actually signed.

For a route that doesn't need a full `ServiceIntegration`, apply the
`webhook` middleware alias directly — it reads the same `config('services.<name>')`
block described above, so the route declaration itself never carries a secret:

```php
$router->post('/webhooks/stripe', [WebhookController::class, 'handle'])
    ->middleware('webhook:stripe');
```

It throws a `400 HttpException` (not 401/403 — an invalid signature here
almost always means a misconfigured secret or an unverifiable request, not a
credentialed-but-unauthorized caller) if `services.stripe.webhook_secret`
is unset, the configured header is missing, or the signature doesn't match.
Exempt the same route from CSRF, since it's a cross-origin POST by
definition — see [Security Hardening](security.md#csrf-protection)'s
`csrf_except` pattern (`'webhooks/*'`).

## What's still missing

These three primitives are deliberately the *groundwork*, not a finished
integration — there is still no bundled payment/SDK package (a `marrow/billing`
or similar) shipping a ready-made `StripeIntegration`. Building one is
unblocked by this groundwork, not replaced by it.
