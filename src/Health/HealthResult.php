<?php

declare(strict_types=1);

namespace Marrow\Health;

/**
 * Result of a single health check.
 */
final class HealthResult
{
    public const OK = 'ok';
    public const WARN = 'warning';
    public const FAIL = 'failed';

    public function __construct(
        public readonly string $status,
        public readonly string $message = '',
        public readonly array $meta = []
    ) {
    }

    public static function ok(string $message = 'OK', array $meta = []): self
    {
        return new self(self::OK, $message, $meta);
    }

    public static function warn(string $message, array $meta = []): self
    {
        return new self(self::WARN, $message, $meta);
    }

    public static function fail(string $message, array $meta = []): self
    {
        return new self(self::FAIL, $message, $meta);
    }

    public function isHealthy(): bool
    {
        return $this->status !== self::FAIL;
    }

    public function toArray(): array
    {
        return array_filter([
            'status'  => $this->status,
            'message' => $this->message,
            'meta'    => $this->meta ?: null,
        ], static fn ($v) => $v !== null);
    }
}
