<?php

declare(strict_types=1);

namespace Marrow\Middleware;

/**
 * Single source of truth for turning middleware references into concrete,
 * pipeline-ready entries. Shared by the HTTP Kernel (global stack) and the
 * Router (route/group stack) so both resolve identically.
 *
 * A reference can be:
 *   - a group name      → expanded recursively to its members
 *   - an alias          → mapped to its class name
 *   - a class name      → passed through unchanged
 *   - 'name:a,b'        → the ':params' suffix is preserved on the resolved class
 *   - an object         → passed through (pre-instantiated middleware)
 *
 * The output is always a flat list of "FQCN", "FQCN:params" strings, or objects,
 * which Pipeline::resolve() knows how to handle.
 */
class MiddlewareResolver
{
    /**
     * @param array<string, string>        $aliases alias  → class name
     * @param array<string, array<string>> $groups  group  → list of references
     */
    public function __construct(
        private array $aliases = [],
        private array $groups = []
    ) {
    }

    public function setAliases(array $aliases): void
    {
        $this->aliases = $aliases;
    }

    public function setGroups(array $groups): void
    {
        $this->groups = $groups;
    }

    /**
     * Expand a stack of middleware references to concrete entries.
     *
     * @param array<mixed> $middlewares
     * @return array<string|object>
     */
    public function resolve(array $middlewares): array
    {
        $result = [];
        foreach ($middlewares as $reference) {
            foreach ($this->resolveOne($reference) as $entry) {
                $result[] = $entry;
            }
        }
        return $result;
    }

    /**
     * @param array<string> $seen Group names already expanded on this branch (cycle guard).
     * @return array<string|object>
     */
    private function resolveOne(mixed $reference, array $seen = []): array
    {
        // Pre-instantiated middleware (or anything non-string) passes through.
        if (!is_string($reference)) {
            return [$reference];
        }

        // Peel off an optional ":params" suffix before any lookup.
        $name   = $reference;
        $params = null;
        if (str_contains($reference, ':')) {
            [$name, $params] = explode(':', $reference, 2);
        }

        // Group → expand recursively (guarding against self-referential cycles).
        if (isset($this->groups[$name])) {
            if (in_array($name, $seen, true)) {
                return [];
            }
            $seen[] = $name;

            $expanded = [];
            foreach ((array) $this->groups[$name] as $member) {
                foreach ($this->resolveOne($member, $seen) as $entry) {
                    $expanded[] = $entry;
                }
            }
            return $expanded;
        }

        // Alias → class name (or pass the class name through unchanged).
        $class = $this->aliases[$name] ?? $name;

        return [$params !== null ? "{$class}:{$params}" : $class];
    }
}
