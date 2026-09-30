<?php

declare(strict_types=1);

namespace Marrow\Config;

/**
 * Configuration repository with dot-notation access.
 * Populated from PHP config files; env() values resolved via $_ENV.
 */
class Repository
{
    private array $items = [];

    public function set(string $key, mixed $value): void
    {
        $this->setNested($this->items, explode('.', $key), $value);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->getNested($this->items, explode('.', $key)) ?? $default;
    }

    public function has(string $key): bool
    {
        return $this->getNested($this->items, explode('.', $key)) !== null;
    }

    public function all(): array
    {
        return $this->items;
    }

    private function getNested(array $items, array $segments): mixed
    {
        foreach ($segments as $segment) {
            if (!is_array($items) || !array_key_exists($segment, $items)) {
                return null;
            }
            $items = $items[$segment];
        }
        return $items;
    }

    private function setNested(array &$items, array $segments, mixed $value): void
    {
        $key = array_shift($segments);
        if (empty($segments)) {
            $items[$key] = $value;
        } else {
            if (!isset($items[$key]) || !is_array($items[$key])) {
                $items[$key] = [];
            }
            $this->setNested($items[$key], $segments, $value);
        }
    }
}
