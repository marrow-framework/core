# Architecture & Request Lifecycle

## The Application kernel

`Marrow\Application` (`framework/src/Application.php`) is the single IoC
kernel for both HTTP and console contexts. Constructing it:

1. Stores itself as a process-wide singleton (`Application::getInstance()`).
2. Creates the `Container` and binds `Application`/`Container` into it.
3. Loads `.env` via `vlucas/phpdotenv` (`safeLoad()` — missing `.env` is not
   an error).
4. Calls `bindCoreServices()`, which:
   - Creates the `Config\Repository` and immediately loads `app`, `database`,
     `logging`, `session`, `cache`, `auth`, `middleware`, `filesystems`,
     `rbac`, `mail`, `queue`, `notifications`, `cors`, `shield`, `services`,
     `health` from `config/*.php`.
   - Registers ~25 core services as container singletons: `Logger`,
     `Dispatcher`, `Router`, `Template\Engine`, `Connection`, `ModuleManager`,
     `Http\Kernel`, `Exceptions\Handler`, `Console\Kernel`, `SessionManager`,
     `CacheManager`, `AuthManager`, `Gate` (with the super-admin `before`
     hook wired from `rbac.super_admin_role`), `ValidatorFactory`,
     `ComponentRegistry`, `Storage`, `RateLimiter`, `HttpClient` (non-singleton),
     `Mailer`, `QueueManager`, `Worker`, `Schedule`, `NotificationManager`,
     `HealthManager` (with `DatabaseHealthCheck`, `CacheHealthCheck`,
     `DiskSpaceHealthCheck`, `QueueHealthCheck` pre-registered, driven by
     `config/health.php`).

None of this touches modules or routes yet — that only happens in the
private `boot()` method, called by whichever of the two entry points below
runs.

### Conventioned paths

```php
'config'  => $basePath.'/config'
'modules' => $basePath.'/modules'
'storage' => $basePath.'/storage'
'cache'   => $basePath.'/storage/cache'
'logs'    => $basePath.'/storage/logs'
'views'   => $basePath.'/resources/views'
'public'  => $basePath.'/public'
```

Read with `app()->path('storage', 'app/foo.txt')`, or the shortcut helpers
`base_path()`, `storage_path()`, `public_path()`. Override any of them with
`$app->setPath('views', '/elsewhere')` before `boot()` runs if your project
needs a non-standard layout.

## `bootstrap/app.php`

```php
// bootstrap/app.php
use Marrow\Application;
return new Application(dirname(__DIR__));
```

Both entry points below `require` this single file rather than constructing
`Application` themselves — not because the framework exposes a
customization hook there (`boot()` stays private and fixed, always
registering `config/modules.php`'s modules), but simply so the constructor
call isn't duplicated between the HTTP and console entry points.

## The front controller (HTTP)

```php
// public/index.php
require __DIR__ . '/../vendor/autoload.php';
(require __DIR__ . '/../bootstrap/app.php')->handleRequest();
```

`handleRequest()`:

1. Calls the private `boot()` — see [below](#boot-module-registration).
2. Resolves `Http\Kernel` from the container.
3. Builds an `Http\Request` from PHP globals.
4. Calls `$kernel->handle($request)`, then `$response->send()`.

### `Http\Kernel::handle()`

1. Binds the live `Request` instance into the container (request-scoped
   services like the JWT guard can resolve it).
2. `validateAppKey()` — throws if `APP_KEY` is empty (skipped in console
   context so `php forge key:generate` still works).
3. Starts the session (`SessionManager::start()`), if bound.
4. Resolves the global middleware stack from `config/middleware.php`
   (`global`/`aliases`/`groups`) via `MiddlewareResolver`, and shares the
   same aliases/groups with the `Router`.
5. Prepends `HotReloadMiddleware` when `APP_ENV=local` or `APP_DEBUG=true`.
6. Builds a `Middleware\Pipeline`, sends the request through the global
   stack, and terminates with `Router::dispatch($request)`.
7. Any `Throwable` escaping the try block is handed to
   `Exceptions\Handler::render()` — see [Security](security.md) and the
   error-page behaviour described there.

### `Router::dispatch()`

1. Matches the request against the `RouteCollection` (404/405 as
   `HttpException`).
2. Resolves that route's own middleware stack (same resolver, so route/group
   middleware behaves identically to the global stack).
3. Runs a second `Pipeline` through those, terminating in `callAction()`,
   which:
   - Resolves the controller through the `Container` (constructor injection
     works — `TemplateEngine`, `Router`, `Gate`, `Request` for anything
     extending `Http\Controller`).
   - Resolves each action parameter by reflection: a `FormRequest` subclass
     is built from the request and auto-validated; a `Request` type-hint
     gets the current request; any other class type-hint is resolved from
     the container; otherwise it falls back to a matching route parameter,
     the parameter's default, or `null`.
   - Wraps the controller's return value into a `Response` (arrays/objects
     become `JsonResponse`; scalars become an HTML `Response`; a `Response`
     instance passes through unchanged).

See [Routing](routing.md) for the full API and [Middleware](middleware.md)
for the pipeline/resolver mechanics in detail.

## `boot()` — module registration

```php
private function boot(): void
{
    if ($this->booted) return;
    $this->booted = true;

    $manager = $this->container->make(ModuleManager::class);
    foreach ($this->config->get('modules.enabled', []) as $moduleClass) {
        $manager->register($moduleClass);
    }
    $manager->boot();
}
```

`ModuleManager::boot()` validates every declared `imports`, topologically
sorts modules by dependency, registers each module's `exports` into the
container, binds its `providers`, then runs a two-phase
`register()`/`boot()` pass across all modules (routes, view namespaces, and
event listeners are wired during the `boot()` phase). See
[Modules (HMVC)](modules.md) for the full lifecycle and the `#[Module]`
attribute's fields.

`boot()` is idempotent and private — it only runs once, triggered by
whichever of `handleRequest()`/`runConsole()` is called. There is no public
hook to trigger it a second time or independently of those two entry points.

## The console entry point

```php
// forge
#!/usr/bin/env php
<?php
require __DIR__ . '/vendor/autoload.php';
(require __DIR__ . '/bootstrap/app.php')->runConsole();
```

`runConsole()` calls the same private `boot()`, then resolves
`Console\Kernel` and calls `handle()`, which additionally pulls in every
command any enabled module declared via `#[Module(commands: [...])]`
(`ModuleManager::getAllCommands()`) before handing off to Symfony Console.
See [The `forge` CLI](cli.md).

## Request flow, end to end

```
public/index.php
  → bootstrap/app.php (new Application(...))
  → Application::handleRequest()
      → boot() → ModuleManager::boot() → routes loaded, listeners wired
      → Http\Kernel::handle($request)
          → validateAppKey() / bootSession()
          → Pipeline(global middleware) →
              → Router::dispatch($request)
                  → Pipeline(route/group middleware) →
                      → Controller action (DI-resolved params)
                          → Response
          (any Throwable → Exceptions\Handler::render())
      → $response->send()
```

## Directory conventions summary

| Directory | Convention |
|---|---|
| `bootstrap/` | `app.php` builds the `Application` instance — the one place both entry points require |
| `app/{Type}/` | Non-modular, **non-routed** code, namespace `App\{Type}` — routing only ever loads `modules/*/routes.php`, so a `Controller` generated here (no `--module`) has no route loader that will ever find it; reserve `app/` for models, policies, and shared services |
| `modules/{Name}/` | An HMVC module, namespace `Modules\{Name}\...` (see [Modules](modules.md)) |
| `database/migrations/` | Global migrations, discovered alongside every `modules/*/Database/Migrations` |
| `resources/views/` | Root Twig namespace, plus auto-registered `layouts`/`errors`/`components`/`partials`/`emails` sub-namespaces |
| `config/` | One array-returning PHP file per subsystem |
| `storage/` | `cache/` (Twig + app cache), `logs/`, `app/` (filesystem disks) |
