<?php

declare(strict_types=1);

namespace Marrow\Template;

/**
 * Central registry for view components.
 * Components are registered by name (kebab-case) → PHP class.
 *
 * Register from a module's boot():
 *   ComponentRegistry::register(AlertComponent::class);
 */
final class ComponentRegistry
{
    /** @var array<string, class-string<Component>> name → class */
    private array $components = [];

    public function register(string $componentClass): void
    {
        $name = $componentClass::componentName();
        $this->components[$name] = $componentClass;
    }

    /** Register with an explicit alias instead of the derived name. */
    public function registerAs(string $name, string $componentClass): void
    {
        $this->components[$name] = $componentClass;
    }

    /** Returns the component class for $name, or null if not found. */
    public function resolve(string $name): ?string
    {
        return $this->components[$name] ?? null;
    }

    public function all(): array
    {
        return $this->components;
    }

    public function has(string $name): bool
    {
        return isset($this->components[$name]);
    }
}
