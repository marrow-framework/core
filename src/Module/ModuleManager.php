<?php

declare(strict_types=1);

namespace Marrow\Module;

use Marrow\Container;
use Marrow\Events\Dispatcher;
use Marrow\Module\Attributes\Module as ModuleAttr;
use Marrow\Exceptions\ModuleException;
use ReflectionClass;

/**
 * Manages the full module lifecycle:
 *  1. Collect all module metadata via reflection on #[Module()] attributes
 *  2. Validate dependencies (missing imports, cycles)
 *  3. Topological sort → deterministic register/boot order
 *  4. Phase register() then phase boot() across all modules
 */
class ModuleManager
{
    /** @var array<string, BaseModule> FQCN => instance */
    private array $modules = [];

    /** @var array<string, ModuleAttr> FQCN => attribute */
    private array $meta = [];

    /** @var string[] Boot order determined by topological sort */
    private array $bootOrder = [];

    public function __construct(
        private readonly Container $container
    ) {
    }

    /** @throws ModuleException If $moduleClass is missing the #[Module] attribute. */
    public function register(string $moduleClass): void
    {
        if (isset($this->modules[$moduleClass])) {
            return;
        }

        $ref = new ReflectionClass($moduleClass);
        $attrs = $ref->getAttributes(ModuleAttr::class);

        if (empty($attrs)) {
            throw new ModuleException("Class [{$moduleClass}] is missing the #[Module] attribute.");
        }

        /** @var ModuleAttr $attr */
        $attr = $attrs[0]->newInstance();

        $this->meta[$moduleClass] = $attr;

        /** @var BaseModule $instance */
        $instance = new $moduleClass();
        $instance->setContainer($this->container);
        $this->modules[$moduleClass] = $instance;
    }

    /** @throws ModuleException If a declared import isn't registered, or a circular dependency exists between modules. */
    public function boot(): void
    {
        $this->validate();
        $this->bootOrder = $this->topologicalSort();

        // Register all module exports into the container
        foreach ($this->bootOrder as $class) {
            $meta = $this->meta[$class];
            $this->container->registerModuleExports($class, $meta->exports);

            foreach ($meta->providers as $provider) {
                $this->container->bind($provider, $provider, singleton: true, module: $class);
            }
        }

        // Phase 1: register (bindings)
        foreach ($this->bootOrder as $class) {
            $this->modules[$class]->register();
        }

        // Phase 2: boot (routes, listeners, views)
        foreach ($this->bootOrder as $class) {
            $this->bootModule($class);
        }
    }

    private function bootModule(string $class): void
    {
        $module = $this->modules[$class];
        $meta = $this->meta[$class];
        $name = $meta->name;

        // Register Twig namespace — path derived from the module class's own
        // file location (see BaseModule::path()), not the app's local
        // modules/ directory, so a vendor-installed module's Views resolve
        // correctly too.
        $module->registerViewNamespace($name, $module->path('Views'));

        // Load routes — same reflection-based path resolution.
        $module->loadRoutes($module->path('routes.php'));

        // Wire event listeners
        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->container->make(Dispatcher::class);
        foreach ($meta->listeners as $event => $listeners) {
            foreach ((array) $listeners as $listener) {
                $dispatcher->listen($event, $listener);
            }
        }

        $module->boot();
    }

    // ─────────────────────── Validation ──────────────────────────────

    private function validate(): void
    {
        foreach ($this->meta as $meta) {
            foreach ($meta->imports as $dep) {
                if (!isset($this->modules[$dep])) {
                    throw new ModuleException(
                        "Module [{$meta->name}] requires [{$dep}] which is not enabled. " .
                        "Add [{$dep}] to config/modules.php."
                    );
                }
            }
        }
    }

    // ─────────────────────── Topological sort (Kahn) ─────────────────

    private function topologicalSort(): array
    {
        $inDegree = array_fill_keys(array_keys($this->meta), 0);
        $adj = array_fill_keys(array_keys($this->meta), []);

        foreach ($this->meta as $class => $meta) {
            foreach ($meta->imports as $dep) {
                $adj[$dep][] = $class;
                $inDegree[$class]++;
            }
        }

        $queue = [];
        foreach ($inDegree as $class => $degree) {
            if ($degree === 0) {
                $queue[] = $class;
            }
        }

        $order = [];
        while (!empty($queue)) {
            $current = array_shift($queue);
            $order[] = $current;

            foreach ($adj[$current] as $neighbor) {
                $inDegree[$neighbor]--;
                if ($inDegree[$neighbor] === 0) {
                    $queue[] = $neighbor;
                }
            }
        }

        if (count($order) !== count($this->meta)) {
            $cycle = $this->findCycle();
            throw new ModuleException("Module cycle detected — circular dependency: {$cycle}");
        }

        return $order;
    }

    private function findCycle(): string
    {
        $visited = [];
        $path = [];

        foreach (array_keys($this->meta) as $start) {
            if (!isset($visited[$start])) {
                if ($this->dfsHasCycle($start, $visited, $path)) {
                    return implode(' → ', $path);
                }
            }
        }

        return 'unknown cycle';
    }

    private function dfsHasCycle(string $node, array &$visited, array &$path): bool
    {
        $visited[$node] = 'gray';
        $path[] = $this->meta[$node]->name;

        foreach ($this->meta[$node]->imports as $dep) {
            if (!isset($visited[$dep])) {
                if ($this->dfsHasCycle($dep, $visited, $path)) {
                    return true;
                }
            } elseif ($visited[$dep] === 'gray') {
                $path[] = $this->meta[$dep]->name;
                return true;
            }
        }

        $visited[$node] = 'black';
        array_pop($path);
        return false;
    }

    // ─────────────────────── Public utilities ────────────────────────

    public function getBootOrder(): array
    {
        return $this->bootOrder;
    }

    public function getMeta(): array
    {
        return $this->meta;
    }

    public function getModule(string $class): ?BaseModule
    {
        return $this->modules[$class] ?? null;
    }

    /** @return array<string, BaseModule> FQCN => instance */
    public function getLoadedModules(): array
    {
        return $this->modules;
    }

    /**
     * Base directory of every registered module, keyed by FQCN — whether
     * that's the app's local `modules/{Name}/` or a vendor/ Composer
     * package. Used by Migrator::discoverPaths() to find each module's own
     * migrations without assuming a fixed local folder layout.
     *
     * @return array<string, string> FQCN => absolute directory path
     */
    public function getModulePaths(): array
    {
        $paths = [];
        foreach ($this->modules as $class => $module) {
            $paths[$class] = $module->path();
        }
        return $paths;
    }

    /** Return all registered command classes across all modules. */
    public function getAllCommands(): array
    {
        $commands = [];
        foreach ($this->meta as $meta) {
            foreach ($meta->commands as $command) {
                $commands[] = $command;
            }
        }
        return $commands;
    }

    /** Graph visualization for `php craft module:graph`. */
    public function renderGraph(): string
    {
        $lines = ["Module Dependency Graph\n" . str_repeat('─', 60)];

        foreach ($this->bootOrder ?: array_keys($this->meta) as $class) {
            $meta = $this->meta[$class];
            $imports = empty($meta->imports) ? '' : ' ← [' . implode(', ', array_map(
                fn($d) => $this->meta[$d]->name ?? class_basename($d),
                $meta->imports
            )) . ']';
            $exports = empty($meta->exports) ? '' : '  exports: ' . implode(', ', array_map('class_basename', $meta->exports));

            $lines[] = "  ● {$meta->name}{$imports}";
            if ($exports) {
                $lines[] = "     {$exports}";
            }
        }

        return implode("\n", $lines);
    }
}

function class_basename_m(string $class): string
{
    return class_basename($class);
}
