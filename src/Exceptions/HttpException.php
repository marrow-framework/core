<?php

declare(strict_types=1);

namespace Marrow\Exceptions;

use RuntimeException;

/**
 * HTTP-level exception (4xx / 5xx). Caught by the HttpKernel and turned
 * into an appropriate error page or JSON response.
 */
class HttpException extends RuntimeException
{
    public function __construct(
        private readonly int $statusCode,
        string $message = '',
        ?\Throwable $previous = null,
        private readonly array $headers = []
    ) {
        parent::__construct($message ?: $this->defaultMessage(), $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    /** Return a copy carrying the given response headers (merged). */
    public function withHeaders(array $headers): self
    {
        return new self(
            $this->statusCode,
            $this->getMessage(),
            $this->getPrevious(),
            array_merge($this->headers, $headers)
        );
    }

    private function defaultMessage(): string
    {
        return match ($this->statusCode) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            419 => 'Page Expired',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            503 => 'Service Unavailable',
            default => 'HTTP Error',
        };
    }
}
