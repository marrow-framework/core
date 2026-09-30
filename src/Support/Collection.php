<?php

declare(strict_types=1);

namespace Marrow\Support;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use ArrayIterator;
use JsonSerializable;
use Traversable;

/**
 * Fluent wrapper for arrays of items.
 * Returned by QueryBuilder, Model::all(), and similar methods.
 *
 * @implements ArrayAccess<int|string, mixed>
 * @implements IteratorAggregate<int|string, mixed>
 */
class Collection implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    /** @param array<int|string, mixed> $items */
    public function __construct(private array $items = []) {}

    /** @param array<int|string, mixed> $items */
    public static function make(array $items = []): static
    {
        return new static($items);
    }

    // ─────────────────────── Transformations ─────────────────────────

    public function map(callable $callback): static
    {
        return new static(array_map($callback, $this->items));
    }

    public function filter(?callable $callback = null): static
    {
        return new static(array_values(
            $callback !== null ? array_filter($this->items, $callback) : array_filter($this->items)
        ));
    }

    public function reject(callable $callback): static
    {
        return $this->filter(fn($item) => !$callback($item));
    }

    public function reduce(callable $callback, mixed $initial = null): mixed
    {
        return array_reduce($this->items, $callback, $initial);
    }

    public function each(callable $callback): static
    {
        foreach ($this->items as $key => $item) {
            if ($callback($item, $key) === false) {
                break;
            }
        }
        return $this;
    }

    public function pluck(string $key): static
    {
        return new static(array_map(
            fn($item) => is_array($item) ? ($item[$key] ?? null) : ($item->{$key} ?? null),
            $this->items
        ));
    }

    public function sortBy(string|callable $key, bool $descending = false): static
    {
        $items = $this->items;
        usort($items, function ($a, $b) use ($key, $descending) {
            $va = is_callable($key) ? $key($a) : (is_array($a) ? ($a[$key] ?? null) : ($a->{$key} ?? null));
            $vb = is_callable($key) ? $key($b) : (is_array($b) ? ($b[$key] ?? null) : ($b->{$key} ?? null));
            $result = $va <=> $vb;
            return $descending ? -$result : $result;
        });
        return new static($items);
    }

    public function sortByDesc(string|callable $key): static
    {
        return $this->sortBy($key, true);
    }

    public function groupBy(string|callable $key): static
    {
        $groups = [];
        foreach ($this->items as $item) {
            $groupKey = is_callable($key) ? $key($item) : (is_array($item) ? ($item[$key] ?? null) : ($item->{$key} ?? null));
            $groups[$groupKey][] = $item;
        }
        return new static(array_map(fn($g) => new static($g), $groups));
    }

    public function keyBy(string|callable $key): static
    {
        $result = [];
        foreach ($this->items as $item) {
            $k = is_callable($key) ? $key($item) : (is_array($item) ? ($item[$key] ?? null) : ($item->{$key} ?? null));
            $result[$k] = $item;
        }
        return new static($result);
    }

    public function unique(?string $key = null): static
    {
        if ($key === null) {
            return new static(array_values(array_unique($this->items)));
        }
        $seen   = [];
        $result = [];
        foreach ($this->items as $item) {
            $val = is_array($item) ? ($item[$key] ?? null) : ($item->{$key} ?? null);
            if (!in_array($val, $seen, true)) {
                $seen[]   = $val;
                $result[] = $item;
            }
        }
        return new static($result);
    }

    public function flatten(int $depth = PHP_INT_MAX): static
    {
        $result = [];
        foreach ($this->items as $item) {
            if ($item instanceof static) {
                $item = $item->toArray();
            }
            if (is_array($item) && $depth > 0) {
                $result = array_merge($result, (new static($item))->flatten($depth - 1)->toArray());
            } else {
                $result[] = $item;
            }
        }
        return new static($result);
    }

    public function chunk(int $size): static
    {
        $chunks = array_chunk($this->items, $size);
        return new static(array_map(fn($c) => new static($c), $chunks));
    }

    public function take(int $n): static
    {
        return new static(array_slice($this->items, 0, $n));
    }

    public function skip(int $n): static
    {
        return new static(array_values(array_slice($this->items, $n)));
    }

    public function values(): static
    {
        return new static(array_values($this->items));
    }

    public function keys(): static
    {
        return new static(array_keys($this->items));
    }

    /** @param array<int|string, mixed>|self $items */
    public function merge(array|self $items): static
    {
        $items = $items instanceof static ? $items->toArray() : $items;
        return new static(array_merge($this->items, $items));
    }

    public function reverse(): static
    {
        return new static(array_reverse($this->items));
    }

    // ─────────────────────── Aggregates ──────────────────────────────

    public function sum(string|callable|null $key = null): float
    {
        if ($key === null) {
            return (float) array_sum($this->items);
        }
        return $this->pluck($key)->sum();
    }

    public function avg(string|callable|null $key = null): float
    {
        $count = $this->count();
        return $count > 0 ? $this->sum($key) / $count : 0.0;
    }

    public function min(?string $key = null): float|null
    {
        $items = $key ? $this->pluck($key)->toArray() : $this->items;
        return empty($items) ? null : (float) min($items);
    }

    public function max(?string $key = null): float|null
    {
        $items = $key ? $this->pluck($key)->toArray() : $this->items;
        return empty($items) ? null : (float) max($items);
    }

    // ─────────────────────── Search / check ──────────────────────────

    public function first(?callable $callback = null): mixed
    {
        if ($callback === null) {
            return !empty($this->items) ? reset($this->items) : null;
        }
        foreach ($this->items as $item) {
            if ($callback($item)) {
                return $item;
            }
        }
        return null;
    }

    public function last(?callable $callback = null): mixed
    {
        if ($callback === null) {
            return !empty($this->items) ? end($this->items) : null;
        }
        return $this->filter($callback)->last();
    }

    public function contains(mixed $keyOrCallback, mixed $value = null): bool
    {
        if (is_callable($keyOrCallback)) {
            foreach ($this->items as $item) {
                if ($keyOrCallback($item)) {
                    return true;
                }
            }
            return false;
        }
        if ($value !== null) {
            foreach ($this->items as $item) {
                $v = is_array($item) ? ($item[$keyOrCallback] ?? null) : ($item->{$keyOrCallback} ?? null);
                if ($v === $value) {
                    return true;
                }
            }
            return false;
        }
        return in_array($keyOrCallback, $this->items, true);
    }

    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    // ─────────────────────── Conversion ──────────────────────────────

    /** @return array<int|string, mixed> Items recursively converted via their own toArray(), where present. */
    public function toArray(): array
    {
        return array_map(function ($item) {
            if ($item instanceof self) {
                return $item->toArray();
            }
            if (is_object($item) && method_exists($item, 'toArray')) {
                return $item->toArray();
            }
            return $item;
        }, $this->items);
    }

    public function toJson(): string
    {
        return (string) json_encode($this->toArray());
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    // ─────────────────────── ArrayAccess ─────────────────────────────

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }

    // ─────────────────────── Countable / Iterator ────────────────────

    public function count(): int
    {
        return count($this->items);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    /** @return array<int|string, mixed> The raw, un-recursed items — see toArray() for a deep conversion. */
    public function all(): array
    {
        return $this->items;
    }
}
