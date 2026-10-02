<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Marrow\Config\Repository as ConfigRepository;
use Marrow\Http\HttpClient;
use Marrow\Http\Request;
use Marrow\Http\Webhook\WebhookSignature;
use Marrow\Support\ServiceIntegration;

// ── Tests ─────────────────────────────────────────────────────────────────────
//
// ServiceIntegration is the base class every future external-service package
// (a Stripe wrapper, etc.) is meant to extend — see docs/integrations.md.
// ConfigRepository is a plain, directly-constructed in-memory instance here
// (same reasoning as VerifyWebhookSignatureTest): no Application singleton
// involved, so these tests can't leak state into any other test file.

function fixtureConfig(array $services): ConfigRepository
{
    $config = new ConfigRepository();
    $config->set('services', $services);
    return $config;
}

/** @return mixed The private $options array of an HttpClient, via reflection. */
function httpClientOptions(HttpClient $http): array
{
    return (new \ReflectionProperty(HttpClient::class, 'options'))->getValue($http);
}

class FixtureIntegration extends ServiceIntegration
{
    protected static function key(): string
    {
        return 'fixture';
    }

    public function debugHttp(): HttpClient
    {
        return $this->http();
    }

    public function debugSetting(string $key, mixed $default = null): mixed
    {
        return $this->setting($key, $default);
    }

    public function debugVerifyWebhook(Request $request, string $header = 'X-Webhook-Signature', int $tolerance = 0): bool
    {
        return $this->verifyWebhook($request, $header, $tolerance);
    }
}

class CustomTokenKeyIntegration extends ServiceIntegration
{
    protected static function key(): string
    {
        return 'fixture';
    }

    protected function tokenConfigKey(): string
    {
        return 'api_key';
    }

    public function debugHttp(): HttpClient
    {
        return $this->http();
    }
}

test('http() applies base_uri and a bearer token read from its own config block', function () {
    $config = fixtureConfig(['fixture' => [
        'base_uri' => 'https://api.fixture.example/v1/',
        'secret' => 'sk_test_123',
    ]]);

    $integration = new FixtureIntegration(HttpClient::create(), $config);
    $options = httpClientOptions($integration->debugHttp());

    expect($options['base_uri'])->toBe('https://api.fixture.example/v1/');
    expect($options['headers']['Authorization'])->toBe('Bearer sk_test_123');
});

test('http() omits the Authorization header entirely when no secret is configured', function () {
    $config = fixtureConfig(['fixture' => ['base_uri' => 'https://api.fixture.example/v1/']]);

    $integration = new FixtureIntegration(HttpClient::create(), $config);
    $options = httpClientOptions($integration->debugHttp());

    expect($options)->not->toHaveKey('headers');
});

test('tokenConfigKey() can be overridden for a provider that names its credential differently', function () {
    $config = fixtureConfig(['fixture' => ['api_key' => 'custom-key-value']]);

    $integration = new CustomTokenKeyIntegration(HttpClient::create(), $config);
    $options = httpClientOptions($integration->debugHttp());

    expect($options['headers']['Authorization'])->toBe('Bearer custom-key-value');
});

test('setting() reads this integration\'s own config block and falls back to the given default', function () {
    $config = fixtureConfig(['fixture' => ['some_flag' => true]]);

    $integration = new FixtureIntegration(HttpClient::create(), $config);

    expect($integration->debugSetting('some_flag'))->toBeTrue();
    expect($integration->debugSetting('missing_key', 'fallback'))->toBe('fallback');
});

test('verifyWebhook() delegates to WebhookSignature using this integration\'s webhook_secret', function () {
    $secret = 'whsec_fixture';
    $payload = '{"ok":true}';
    $config = fixtureConfig(['fixture' => ['webhook_secret' => $secret]]);

    $integration = new FixtureIntegration(HttpClient::create(), $config);

    $request = Request::create('/webhooks/fixture', 'POST', content: $payload);
    $request->headers->set('X-Webhook-Signature', WebhookSignature::sign($payload, $secret));

    expect($integration->debugVerifyWebhook($request))->toBeTrue();
});

test('verifyWebhook() fails closed when no webhook_secret is configured', function () {
    $config = fixtureConfig(['fixture' => []]);
    $integration = new FixtureIntegration(HttpClient::create(), $config);

    $request = Request::create('/webhooks/fixture', 'POST', content: 'payload');
    $request->headers->set('X-Webhook-Signature', 'anything');

    expect($integration->debugVerifyWebhook($request))->toBeFalse();
});
