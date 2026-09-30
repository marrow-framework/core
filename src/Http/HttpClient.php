<?php

declare(strict_types=1);

namespace Marrow\Http;

use Symfony\Component\HttpClient\HttpClient as SymfonyHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Fluent HTTP client — a thin, batteries-included wrapper around
 * symfony/http-client. Never import Symfony's client directly in app code;
 * inject this service via constructor instead.
 *
 * Usage (inject HttpClient via constructor):
 *   $http->get('https://api.example.com/users');
 *   $http->withToken($jwt)->post($url, ['json' => $payload])->json();
 *   $http->asForm()->post($url, ['body' => ['a' => 1]]);
 *   $http->retry(3)->timeout(5)->get($url)->json('data.items');
 */
class HttpClient
{
    private array $options = [];
    private int $retries = 0;
    private int $retryDelayMs = 200;

    public function __construct(private readonly HttpClientInterface $client)
    {
    }

    public static function create(array $defaultOptions = []): self
    {
        return new self(SymfonyHttpClient::create($defaultOptions));
    }

    // ── Fluent configuration ────────────────────────────────────────────

    public function withHeaders(array $headers): self
    {
        $clone = clone $this;
        $clone->options['headers'] = array_merge($this->options['headers'] ?? [], $headers);
        return $clone;
    }

    public function withToken(string $token, string $type = 'Bearer'): self
    {
        return $this->withHeaders(['Authorization' => trim($type . ' ' . $token)]);
    }

    public function withBasicAuth(string $user, string $password): self
    {
        $clone = clone $this;
        $clone->options['auth_basic'] = [$user, $password];
        return $clone;
    }

    public function acceptJson(): self
    {
        return $this->withHeaders(['Accept' => 'application/json']);
    }

    public function asForm(): self
    {
        return $this->withHeaders(['Content-Type' => 'application/x-www-form-urlencoded']);
    }

    public function baseUri(string $uri): self
    {
        $clone = clone $this;
        $clone->options['base_uri'] = $uri;
        return $clone;
    }

    public function timeout(float $seconds): self
    {
        $clone = clone $this;
        $clone->options['timeout'] = $seconds;
        return $clone;
    }

    public function retry(int $times, int $delayMs = 200): self
    {
        $clone = clone $this;
        $clone->retries = max(0, $times);
        $clone->retryDelayMs = $delayMs;
        return $clone;
    }

    // ── Verbs ────────────────────────────────────────────────────────────

    public function get(string $url, array $query = []): HttpResponse
    {
        $opts = $query ? ['query' => $query] : [];
        return $this->request('GET', $url, $opts);
    }

    public function post(string $url, array $options = []): HttpResponse
    {
        return $this->request('POST', $url, $options);
    }

    public function put(string $url, array $options = []): HttpResponse
    {
        return $this->request('PUT', $url, $options);
    }

    public function patch(string $url, array $options = []): HttpResponse
    {
        return $this->request('PATCH', $url, $options);
    }

    public function delete(string $url, array $options = []): HttpResponse
    {
        return $this->request('DELETE', $url, $options);
    }

    public function request(string $method, string $url, array $options = []): HttpResponse
    {
        $merged = array_merge_recursive($this->options, $options);

        $attempt = 0;
        beginning:
        try {
            $response = $this->client->request($method, $url, $merged);
            // Force the transfer to complete so transport errors surface here.
            $response->getStatusCode();
            return new HttpResponse($response);
        } catch (TransportExceptionInterface $e) {
            if ($attempt < $this->retries) {
                $attempt++;
                usleep($this->retryDelayMs * 1000 * $attempt);
                goto beginning;
            }
            throw $e;
        }
    }

    /**
     * Fire multiple requests concurrently and return their responses, keyed
     * the same way as the input array.
     *
     * Note: unlike request(), pooled responses are lazy — the underlying
     * transfers are not forced to complete here, so transport errors surface
     * only when the caller accesses a wrapped HttpResponse (deferred-error
     * contract). For the same reason, pool() does NOT apply the retry()
     * policy; retries only cover the eager request()/verb methods.
     *
     * @param array<string, array{0:string,1:string,2?:array}> $requests
     *        e.g. ['users' => ['GET', '/users'], 'posts' => ['GET', '/posts']]
     * @return array<string, HttpResponse>
     */
    public function pool(array $requests): array
    {
        $responses = [];
        foreach ($requests as $key => $tuple) {
            [$method, $url] = $tuple;
            $merged = array_merge_recursive($this->options, $tuple[2] ?? []);
            $responses[$key] = $this->client->request($method, $url, $merged);
        }

        $result = [];
        foreach ($responses as $key => $response) {
            $result[$key] = new HttpResponse($response);
        }
        return $result;
    }
}
