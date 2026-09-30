# HTTP Client

`Marrow\Http\HttpClient` is a fluent wrapper around `symfony/http-client`
— never import Symfony's client directly in application code, inject
`HttpClient` via the constructor instead.

## Basic requests

```php
class WeatherService
{
    public function __construct(private readonly \Marrow\Http\HttpClient $http) {}

    public function forecast(string $city): array
    {
        return $this->http->get('https://api.weather.example/forecast', ['city' => $city])->json();
    }
}
```

Verbs: `get($url, $query)`, `post`, `put`, `patch`, `delete($url, $options)`,
or `request($method, $url, $options)` directly (Symfony's option array —
`json`, `body`, `headers`, `query`, ...).

## Fluent configuration (each call returns a new clone)

```php
$http->withToken($jwt)
    ->acceptJson()
    ->timeout(5)
    ->retry(3, delayMs: 200)
    ->post('https://api.example.com/orders', ['json' => $payload]);

$http->withBasicAuth('user', 'secret')->get($url);
$http->asForm()->post($url, ['body' => ['field' => 'value']]);
$http->baseUri('https://api.example.com')->get('/users');
```

`retry()` only applies to a `TransportException` (connection-level
failures) and only on the eager `request()`/verb methods — it does not
retry on a non-2xx HTTP status, and it does not apply to `pool()`.

## Reading the response

```php
$response = $http->get($url);

$response->json();              // decoded body
$response->json('data.items');  // dot-notation into the decoded body
$response->getStatusCode();     // via the underlying HttpResponse wrapper — see HttpResponse
```

Calling any verb method forces the transfer to complete immediately (so a
transport error surfaces from that call, not later) — `request()`/the verb
methods have an **eager** error contract.

## Concurrent requests

```php
$responses = $http->pool([
    'users' => ['GET', 'https://api.example.com/users'],
    'posts' => ['GET', 'https://api.example.com/posts'],
]);

$responses['users']->json();
```

`pool()` is **lazy** — the underlying transfers aren't forced to complete
inside `pool()` itself, so a transport error surfaces only when you access
a given entry's response. For the same reason, `pool()` does not apply the
`retry()` policy at all — retries only cover the eager `request()`/verb
methods.

## Default options

```php
// config/services.php
return [
    'http' => [
        'timeout' => 10,
        'headers' => ['User-Agent' => 'MyApp/1.0'],
    ],
];
```

Read by `Application::bindCoreServices()` to build the shared `HttpClient`
instance (`HttpClient::create($config->get('services.http', []))`), so
every injected `HttpClient` starts from these defaults before any
per-call fluent configuration is applied.
