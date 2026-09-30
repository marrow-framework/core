<?php

declare(strict_types=1);

namespace Marrow;

use Marrow\Attributes\Inject;
use Marrow\Exceptions\ContainerException;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * IoC Container with auto-resolution, singleton/transient scopes,
 * interface binding, named bindings, and PHP 8 attribute-based injection.
 *
 * Provider encapsulation: every binding tracks its owner module so cross-module
 * access can be validated at resolution time.
 */
class Container
{
    /** @var array<string, array{factory: callable, singleton: bool}> */
    private array $bindings = [];

    /** @var array<string, object> */
    private array $instances = [];

    /** @var array<string, string> Module owner for each binding (FQCN) */
    private array $bindingOwners = [];

    /** @var array<string, string[]> Exported bindings per module */
    private array $moduleExports = [];

    /** @var array<string, true> Guards against self-referential factory recursion */
    private array $building = [];

    /**
     * Stack of module FQCNs, innermost resolution last. Lets a caller-supplied
     * or owner-derived module context survive nested/transitive make() calls
     * (constructor injection) without every internal call site having to pass
     * $callerModule explicitly. See make().
     *
     * @var string[]
     */
    private array $moduleContextStack = [];

    /**
     * Cached constructor parameter lists, keyed by FQCN.
     * Instance-level (not static) so each Container instance in tests is isolated.
     *
     * @var array<string, ReflectionParameter[]|null>
     */
    private array $reflectionCache = [];

    // ───────────────────────── Registration ─────────────────────────

    public function bind(string $abstract, callable|string $concrete, bool $singleton = false, ?string $module = null): void
    {
        if (is_string($concrete)) {
            $concrete = fn(Container $c) => $c->make($concrete);
        }

        $this->bindings[$abstract] = [
            'factory' => $concrete,
            'singleton' => $singleton,
        ];

        if ($module !== null) {
            $this->bindingOwners[$abstract] = $module;
        }
    }

    public function singleton(string $abstract, callable|string $concrete, ?string $module = null): void
    {
        $this->bind($abstract, $concrete, singleton: true, module: $module);
    }

    public function instance(string $abstract, object $instance, ?string $module = null): void
    {
        $this->instances[$abstract] = $instance;
        if ($module !== null) {
            $this->bindingOwners[$abstract] = $module;
        }
    }

    /** Register module exports so cross-module access can be verified. */
    public function registerModuleExports(string $moduleClass, array $exports): void
    {
        $this->moduleExports[$moduleClass] = $exports;
    }

    // ───────────────────────── Resolution ───────────────────────────

    /**
     * @param class-string|string $abstract
     *
     * @throws \Marrow\Exceptions\ContainerException If $abstract can't be reflected or
     *         instantiated, a constructor parameter can't be resolved (no type hint,
     *         no default, no #[Inject]), an #[Inject] key matches no binding/config, or
     *         $callerModule resolves a binding its module doesn't export.
     */
    public function make(string $abstract, array $overrides = [], ?string $callerModule = null): mixed
    {
        // No explicit caller given — inherit whichever module is currently
        // being resolved (set below), so isolation survives nested/transitive
        // constructor injection instead of being lost after the first level.
        $effectiveCaller = $callerModule ?? ($this->moduleContextStack === [] ? null : end($this->moduleContextStack));

        $this->validateModuleAccess($abstract, $effectiveCaller);

        // Already a shared instance
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract]; // @phpstan-ignore-line
        }

        // Everything resolved while building $abstract (its own constructor
        // dependencies, transitively) is attributed to $abstract's owning
        // module, if it has one — otherwise it inherits the current caller.
        $owner = $this->bindingOwners[$abstract] ?? $effectiveCaller;
        $this->moduleContextStack[] = $owner;
        try {
            // Explicit binding — skip if we're already inside this factory to break
            // self-referential cycles (e.g. bind(X, X) creates fn($c) => $c->make(X))
            if (isset($this->bindings[$abstract]) && !isset($this->building[$abstract])) {
                $this->building[$abstract] = true;
                try {
                    $binding = $this->bindings[$abstract];
                    $result  = ($binding['factory'])($this, $overrides);
                } finally {
                    unset($this->building[$abstract]);
                }

                if ($binding['singleton']) {
                    $this->instances[$abstract] = $result;
                }

                return $result; // @phpstan-ignore-line
            }

            // Auto-resolve via reflection (also serves as fallback when $building is set)
            return $this->autoResolve($abstract, $overrides);
        } finally {
            array_pop($this->moduleContextStack);
        }
    }

    /** Clears the module-resolution context. Call between independent units of
     *  work in a long-running process (e.g. a queue worker between jobs) so a
     *  failure mid-resolution can never leak module context into the next one. */
    public function resetModuleContext(): void
    {
        $this->moduleContextStack = [];
    }

    public function has(string $abstract): bool
    {
        return isset($this->instances[$abstract]) || isset($this->bindings[$abstract]);
    }

    // ───────────────────────── Auto-resolution ──────────────────────

    private function autoResolve(string $class, array $overrides): object
    {
        if (!array_key_exists($class, $this->reflectionCache)) {
            try {
                $ref = new ReflectionClass($class);
            } catch (ReflectionException $e) {
                throw new ContainerException("Cannot resolve [{$class}]: " . $e->getMessage(), 0, $e);
            }

            if (!$ref->isInstantiable()) {
                throw new ContainerException("Class [{$class}] is not instantiable. Did you forget to bind an interface?");
            }

            $ctor = $ref->getConstructor();
            $this->reflectionCache[$class] = $ctor?->getParameters();
        }

        $params = $this->reflectionCache[$class];

        if ($params === null) {
            return new $class();
        }

        return new $class(...$this->resolveParameters($params, $overrides));
    }

    /**
     * @param ReflectionParameter[] $params
     */
    private function resolveParameters(array $params, array $overrides): array
    {
        $resolved = [];

        foreach ($params as $param) {
            $name = $param->getName();

            // Override by parameter name
            if (array_key_exists($name, $overrides)) {
                $resolved[] = $overrides[$name];
                continue;
            }

            // Override by type name (e.g. [MyService::class => $instance])
            $type = $param->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $typeName = $type->getName();
                if (array_key_exists($typeName, $overrides)) {
                    $resolved[] = $overrides[$typeName];
                    continue;
                }
            }

            // #[Inject('key')] attribute
            $injectAttrs = $param->getAttributes(Inject::class);
            if (!empty($injectAttrs)) {
                $key = $injectAttrs[0]->newInstance()->key;
                $resolved[] = $this->resolveInjectKey($key);
                continue;
            }

            // Type-hinted class — auto-resolve
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $resolved[] = $this->make($type->getName());
                continue;
            }

            // Optional parameter with default
            if ($param->isOptional()) {
                $resolved[] = $param->getDefaultValue();
                continue;
            }

            throw new ContainerException(
                "Cannot resolve parameter [{$name}] — no type hint, no default, no #[Inject]."
            );
        }

        return $resolved;
    }

    private function resolveInjectKey(string $key): mixed
    {
        // config.app.name → Config\Repository::get('app.name')
        if (str_starts_with($key, 'config.')) {
            $configKey = substr($key, 7);
            /** @var Config\Repository $config */
            $config = $this->make(\Marrow\Config\Repository::class);
            return $config->get($configKey);
        }

        // Named binding
        if ($this->has($key)) {
            return $this->make($key);
        }

        throw new ContainerException("Cannot resolve #[Inject('{$key}')]: no matching binding or config key.");
    }

    // ───────────────────────── Module access control ─────────────────

    private function validateModuleAccess(string $abstract, ?string $callerModule): void
    {
        $owner = $this->bindingOwners[$abstract] ?? null;

        if ($owner === null || $callerModule === null || $owner === $callerModule) {
            return;
        }

        $exports = $this->moduleExports[$owner] ?? [];

        if (!in_array($abstract, $exports, true)) {
            $short = \class_basename($abstract);
            throw new ContainerException(
                "[{$short}] belongs to module [{$owner}] which is not exported for module [{$callerModule}]."
            );
        }
    }
}
