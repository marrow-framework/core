<?php

declare(strict_types=1);

namespace Marrow\Database;

use Marrow\Support\Collection;

/**
 * Model factory base class with a minimal built-in fake data generator.
 * No external libraries needed.
 */
abstract class Factory
{
    private int $count = 1;
    private array $overrides = [];
    private ?string $modelClass = null;

    abstract public function definition(): array;

    public static function new(): static
    {
        return new static();
    }

    public function count(int $n): static
    {
        $clone = clone $this;
        $clone->count = $n;
        return $clone;
    }

    public function for(string $modelClass): static
    {
        $clone = clone $this;
        $clone->modelClass = $modelClass;
        return $clone;
    }

    public function state(array $attributes): static
    {
        $clone = clone $this;
        $clone->overrides = array_merge($clone->overrides, $attributes);
        return $clone;
    }

    public function make(array $extra = []): Collection
    {
        $items = [];
        for ($i = 0; $i < $this->count; $i++) {
            $items[] = array_merge($this->definition(), $this->overrides, $extra);
        }
        return new Collection($items);
    }

    public function create(array $extra = []): Collection
    {
        $models = [];
        $class = $this->resolveModelClass();

        for ($i = 0; $i < $this->count; $i++) {
            $data = array_merge($this->definition(), $this->overrides, $extra);
            $models[] = $class::create($data);
        }

        return new Collection($models);
    }

    private function resolveModelClass(): string
    {
        if ($this->modelClass) {
            return $this->modelClass;
        }
        // Guess from factory name: PostFactory → Post (same namespace)
        $factoryClass = static::class;
        $modelClass = str_replace(['Factories\\', 'Factory'], ['Models\\', ''], $factoryClass);
        return $modelClass;
    }

    // ─────────────────────── Fake helpers ────────────────────────────

    protected function fake(string $locale = 'en_US'): FakeGenerator
    {
        return new FakeGenerator($locale);
    }
}
