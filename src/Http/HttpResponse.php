<?php

declare(strict_types=1);

namespace Marrow\Http;

use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Wraps a symfony/http-client response with Laravel-style accessors.
 */
class HttpResponse
{
    private ?array $decoded = null;

    public function __construct(private readonly ResponseInterface $response)
    {
    }

    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    public function successful(): bool
    {
        return $this->status() >= 200 && $this->status() < 300;
    }

    public function failed(): bool
    {
        return !$this->successful();
    }

    public function clientError(): bool
    {
        return $this->status() >= 400 && $this->status() < 500;
    }

    public function serverError(): bool
    {
        return $this->status() >= 500;
    }

    public function body(): string
    {
        return $this->response->getContent(false);
    }

    public function header(string $name): ?string
    {
        $headers = $this->response->getHeaders(false);
        $value = $headers[strtolower($name)] ?? null;
        return is_array($value) ? ($value[0] ?? null) : $value;
    }

    public function headers(): array
    {
        return $this->response->getHeaders(false);
    }

    /**
     * Decode the JSON body. Pass a dot-path to drill into the result.
     *
     *   $res->json();              // full array
     *   $res->json('data.id');     // nested value
     */
    public function json(?string $key = null, mixed $default = null): mixed
    {
        if ($this->decoded === null) {
            $decoded = json_decode($this->body(), true);
            $this->decoded = is_array($decoded) ? $decoded : [];
        }

        if ($key === null) {
            return $this->decoded;
        }

        $value = $this->decoded;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public function object(): object
    {
        return (object) (json_decode($this->body(), false) ?? new \stdClass());
    }

    /**
     * Throws when the response indicates an error (4xx/5xx or a transport
     * failure — see failed()).
     *
     * @throws \RuntimeException
     */
    public function throw(): self
    {
        if ($this->failed()) {
            throw new \RuntimeException(
                "HTTP request failed with status {$this->status()}: " . $this->body()
            );
        }
        return $this;
    }

    public function toResponse(): ResponseInterface
    {
        return $this->response;
    }
}
