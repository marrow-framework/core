# Modules (HMVC)

Marrow organises application code into **HMVC modules** — self-contained
units with their own controllers, models, views, routes, migrations, and
console commands, wired together by explicit `imports`/`exports` rather than
global auto-discovery.

## Anatomy of a module

```
modules/Blog/
├── BlogModule.php
├── routes.php
├── Controllers/
├── Models/
├── Services/
├── Views/                    Twig namespace @blog/...
├── Commands/
├── Events/
├── Listeners/
├── Policies/
└── Database/
    ├── Migrations/           discovered by the Migrator
    ├── Seeders/
    └── Factories/
```

Generate this whole layout with:

```bash
php forge make:module Blog
```

## The `#[Module]` attribute

```php
namespace Modules\Blog;

use Marrow\Module\Attributes\Module;
use Marrow\Module\BaseModule;

#[Module(
    name: 'blog',
    imports: [\Modules\Auth\AuthModule::class],
    providers: [\Modules\Blog\Services\PostService::class],
    exports: [\Modules\Blog\Services\PostService::class],
    commands: [\Modules\Blog\Commands\PruneDraftsCommand::class],
    listeners: [
        \Modules\Auth\Events\UserRegistered::class => [
            \Modules\Blog\Listeners\CreateWelcomePost::class,
        ],
    ],
)]
class BlogModule extends BaseModule
{
}
```

| Field | Meaning |
|---|---|
| `name` | lowercase identifier — becomes the Twig namespace (`@blog/...`) |
| `imports` | other module classes this module depends on; missing ones throw at boot |
| `providers` | services bound as singletons in the container, owned by this module |
| `exports` | subset of `providers` (or any binding) importers may resolve — see [Container](container.md#module-isolation) |
| `commands` | console command classes registered once the app boots |
| `listeners` | `[EventClass => [ListenerClass, ...]]`, wired via the `Dispatcher` |

Enable the module in `config/modules.php`:

```php
'enabled' => [
    \Modules\Home\HomeModule::class,
    \Modules\Blog\BlogModule::class,
],
```

Declaration order in `enabled` doesn't matter — `ModuleManager` computes the
actual boot order from `imports`.

## Lifecycle

`ModuleManager::boot()` (`framework/src/Module/ModuleManager.php`) runs once,
from `Application::boot()`:

1. **Validate** — every `imports` entry must be a registered module, or a
   `ModuleException` names exactly which module/dependency is missing.
2. **Topological sort** (Kahn's algorithm) — computes a deterministic boot
   order so a module never boots before something it imports. A cycle
   raises `ModuleException` with the full cycle path
   (`A → B → C → A`).
3. **Register exports/providers** — for each module in boot order:
   `Container::registerModuleExports()`, then bind each `providers` entry as
   a module-owned singleton.
4. **Phase 1 — `register()`** — every module's `register()` runs, in boot
   order. Use this for anything that only needs the container (extra
   bindings, `Gate::policy()` registration, etc.) — **not** routes or views,
   since other modules' exports may not be fully wired to your consumers'
   expectations until phase 2.
5. **Phase 2 — `boot()`** — for each module, in order: its Twig namespace is
   registered (`modules/{Name}/Views` → `@{name}`), its `routes.php` is
   `require`d (with `$router` already in scope), its declared `listeners`
   are attached to the `Dispatcher`, then the module's own `boot()` method
   runs.

`BaseModule` (`framework/src/Module/BaseModule.php`) gives every module class
protected accessors — `getRouter()`, `getEvents()`, `getView()` — and the
two lifecycle methods to override:

```php
class BlogModule extends BaseModule
{
    public function register(): void
    {
        // extra container bindings not worth listing in providers:
    }

    public function boot(): void
    {
        $this->getEvents()->listen(PostPublished::class, NotifySubscribers::class);
    }
}
```

Both are optional no-ops by default.

## Routes

`routes.php` is `require`d with a local `$router` variable already bound —
no import needed:

```php
// modules/Blog/routes.php
use Modules\Blog\Controllers\PostController;

$router->get('/posts', [PostController::class, 'index'])->name('posts.index');
$router->resource('posts', PostController::class)->middleware('auth');
```

See [Routing](routing.md).

## Views

A module's `Views/` directory is auto-registered as a Twig namespace named
after `#[Module(name: ...)]`:

```php
return $this->view('@blog/posts/index', ['posts' => $posts]);
```

See [Templating (Twig)](templating.md).

## Migrations

`Migrator::discoverPaths()` looks for migrations in `database/migrations`
**and** every `modules/*/Database/Migrations` (or `modules/*/Migrations`) —
so a module's own migrations run alongside the app's global ones with no
extra wiring. Migration commands also resolve each *registered* module's own
directory via reflection (see below), so a module's migrations are found
correctly even when it isn't physically inside the app's local `modules/`
folder. See [Migrations & Schema](migrations.md).

## Distributing a module as a package

A module's `Views/`, `routes.php`, and `Database/Migrations/` don't have to
live under the app's local `modules/` directory at all — `BaseModule::path()`
derives them by reflecting on the module class's **own** file location:

```php
public function path(string $suffix = ''): string
{
    $dir = dirname((new \ReflectionClass($this))->getFileName());
    return $suffix === '' ? $dir : $dir . '/' . ltrim($suffix, '/');
}
```

This is what lets a module be shipped as an ordinary Composer package —
`vendor/acme/blog-module/src/BlogModule.php` resolves its own `Views/` at
`vendor/acme/blog-module/src/Views/` automatically, exactly like a local
module resolves `modules/Blog/Views/`. Nothing about the module class itself
changes; only where its files physically live does.

### Auto-discovery

A package can register its own module without the app editing
`config/modules.php` at all, by declaring it in the package's own
`composer.json`:

```json
{
    "name": "acme/blog-module",
    "extra": {
        "marrow": {
            "modules": ["Acme\\BlogModule\\BlogModule"]
        }
    }
}
```

`Marrow\Module\PackageDiscovery::discover()` reads
`vendor/composer/installed.json` (a file Composer always generates) for this
key across every installed package, and `Application::boot()` merges the
result with `config/modules.php`'s `'enabled'` array before registering
anything. A `composer require acme/blog-module` is then enough on its own —
no manual step. To opt a discovered module back out without uninstalling
the package:

```php
// config/modules.php
return [
    'enabled' => [...],
    'disabled' => [\Acme\BlogModule\BlogModule::class],
];
```

Discovery fails silently (returns nothing) if `installed.json` is missing or
unreadable — an unusual install layout should never be able to hard-crash
boot over an optional convenience.

## Introspection

```bash
php forge module:graph
```

Prints the dependency graph in boot order, with each module's imports and
exports — useful for spotting an accidental import you don't actually need,
or confirming a refactor didn't introduce a cycle.

## Generating module-scoped classes

Most `make:*` commands accept `--module=Name` to generate inside
`modules/Name/...` under the `Modules\Name\...` namespace instead of
`app/...`/`App\...`:

```bash
php forge make:controller PostController --module=Blog --resource
php forge make:policy PostPolicy --model=Modules\\Blog\\Models\\Post --module=Blog
```

When the generated class should be usable by other modules, remember to add
it to that module's `providers`/`exports` — generators that create a class
meant to act as a provider (like `make:policy`) insert it into the target
module's `providers: [...]` array automatically; see
[The `forge` CLI](cli.md).
