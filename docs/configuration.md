# Configuration

## Environment variables

`.env` (at the application root) is loaded with `vlucas/phpdotenv`'s
`safeLoad()` — a missing file is not an error, so it's safe to ship a
container image with real env vars set instead of a `.env` file.

Read a variable with the `env()` helper, never `$_ENV` directly in
application code:

```php
env('APP_DEBUG', false);
```

`env()` auto-casts the string values `true`/`false`/`null`/`empty` (and
their parenthesised forms) to their PHP equivalents; anything else comes
back as a plain string, so cast numbers yourself (`(int) env('DB_PORT', 3306)`).

`.env` is only ever read directly by config files and by a handful of
framework internals that need it before the container exists (`Connection`'s
debug-logging flag, `Crypto`'s key, `SessionManager`'s cookie security, the
JWT guard's secret/TTL fallback). Everywhere else, go through `config()`.

## Config files

Every file in `config/*.php` returns a plain PHP array. `Application::configure($name)`
`require`s `config/{$name}.php` and stores its return value under the key
`$name` in the `Config\Repository`. Fifteen of them are loaded
unconditionally at boot (see [Architecture](architecture.md)); `modules.php`
is loaded separately during `boot()`.

| File | Read by |
|---|---|
| `app.php` | `Application`, `Exceptions\Handler`, `Template\Engine` (debug flag), JWT `iss` claim |
| `database.php` | `Database\Connection` |
| `logging.php` | `Logging\Logger` |
| `cache.php` | `Cache\CacheManager` |
| `auth.php` | `Auth\AuthManager` (guards) |
| `middleware.php` | `Http\Kernel`, `Routing\Router` (via `Middleware\MiddlewareResolver`) |
| `filesystems.php` | `Filesystem\Storage` |
| `rbac.php` | `Auth\Gate`'s super-admin bypass |
| `mail.php` | `Mail\Mailer` |
| `queue.php` | `Queue\QueueManager` |
| `notifications.php` | `Notifications\NotificationManager` |
| `cors.php` | `Middleware\HandleCors` |
| `shield.php` | `Http\Shield\ShieldConfig` (→ `SecurityHeaders`, `VerifyCsrfToken`) |
| `services.php` | `Http\HttpClient` defaults (`services.http`); free-form home for your own API keys |
| `health.php` | `Health\HealthManager`'s registered checks and their thresholds — see [Health Checks](health.md) |
| `modules.php` | `Application::boot()` → `Module\ModuleManager` |

## Reading config

```php
config('app.name');                 // dot notation
config('database.driver', 'sqlite'); // with a default
```

`Config\Repository` (`core/src/Config/Repository.php`) stores the
top-level key per file and supports the same dot notation for reading
nested keys (`config('auth.guards.session.table')`). It has no setter used
by application code beyond tests — treat config as read-only after boot.

> `Application::bindCoreServices()` also tries to load a `session.php`
> (among others) at boot — it's simply skipped if the file doesn't exist
> (`configure()` checks `is_file()` first). The skeleton doesn't ship one
> because nothing in the framework currently reads a `session.*` key (the
> built-in `SessionManager` relies on PHP's native session GC); add it back
> yourself if a custom session driver needs configuration.

## Adding your own config file

Create `config/billing.php` returning an array, then either call
`app()->configure('billing')` yourself early in a module's `register()`, or
simply always read it lazily — `Config\Repository::get()` returns the
default if the top-level key was never loaded, so an unconditional
`config('billing.stripe_key')` from anywhere that runs after your module's
`register()` phase works fine as long as that phase called `configure()`.

## Environment-specific behaviour

Two flags drive most environment-sensitive behaviour, both read straight
from `$_ENV` (not through the container) so they're available even before
config is loaded:

- **`APP_ENV=local`** — enables `HotReloadMiddleware`, relaxes the session
  cookie's `Secure` flag.
- **`APP_DEBUG=true`** — same two effects as above, plus: Twig
  `strict_variables` is on (undefined template variables throw instead of
  silently rendering empty), `Exceptions\Handler` renders the interactive
  debug page instead of a styled error template, and JSON error responses
  include a truncated stack trace.

Never enable `APP_DEBUG` in production — it exposes source snippets,
request headers, and body parameters on every uncaught exception.
