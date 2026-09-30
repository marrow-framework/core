# Getting Started

## Requirements

- PHP 8.2 or later
- Composer 2
- A database driver: SQLite (bundled with PHP), or the `pdo_mysql` / `pdo_pgsql`
  extension for MySQL/PostgreSQL

## Creating a new application

Marrow ships as two repositories: the **framework** (`marrow/framework`,
a library — never run standalone) and the **skeleton** (`marrow/skeleton`,
a ready-to-run application that depends on it). You always start from the skeleton:

```bash
composer create-project marrow/skeleton my-app
cd my-app
```

## Configure the environment

```bash
cp .env.example .env
php forge key:generate
```

`key:generate` writes a random `APP_KEY` into `.env`. It's required for every
HTTP request (`Http\Kernel` refuses to boot without it) and is the base
secret for session/CSRF tokens and the `encrypted` model cast / two-factor
secrets (`Support\Crypto`, AES-256-GCM).

Open `.env` and adjust at least:

| Variable | Purpose |
|---|---|
| `APP_ENV` | `local` enables the dev hot-reload middleware and relaxes cookie `Secure` |
| `APP_DEBUG` | `true` renders the interactive debug page on uncaught exceptions |
| `DB_DRIVER` / `DB_DATABASE` | defaults to SQLite at `storage/database.sqlite` |
| `JWT_SECRET` | required (≥ 32 bytes) only if you use the `jwt` auth guard |

See [Configuration](configuration.md) for the full picture.

## Run the migrations

```bash
php forge migrate
```

The `Account` module's own migrations create `users`, the RBAC tables
(`roles`, `permissions`, `role_permissions`, `user_roles`,
`user_permissions`), two-factor columns, and `audit_logs`; the global
`database/migrations/` only has the queue tables and `notifications`
(infrastructure not owned by any one module — see
[Migrations & Schema](migrations.md)). `Migrator::discoverPaths()` finds
and runs both automatically, no extra configuration needed.

Optionally seed the baseline roles:

```bash
php forge db:seed
```

## Serve the application

```bash
php forge serve
```

Visit `http://localhost:8080` — you should see the bundled `Home` module's
landing page, and `http://localhost:8080/health` should report
`{"status":"ok", ...}`.

`php forge serve` wraps PHP's built-in web server with colourised, timed
request logging and auto-restart on crash. In production, point your web
server's document root at `public/` instead (see
[Architecture](architecture.md#the-front-controller)).

## Anatomy of the skeleton

```
my-app/
├── bin/server.php       Router used by `php forge serve`
├── bootstrap/app.php    Builds the Application instance
├── config/              One file per subsystem
├── database/
│   ├── migrations/      Only what isn't owned by a module (queue, notifications)
│   └── seeders/         Master DatabaseSeeder, delegates to each module's own
├── forge                Console entry point
├── modules/             Your HMVC modules — this is where real work happens
│   ├── Account/         User model + RBAC/2FA/audit schema, no routes
│   │   ├── Models/User.php
│   │   └── Database/{Migrations,Seeders}/
│   └── Home/
│       ├── Controllers/
│       ├── Views/       Twig templates, namespaced @home/...
│       ├── HomeModule.php
│       └── routes.php
├── public/index.php     HTTP front controller
├── resources/views/     Root Twig views + layouts (errors/components/partials/emails on demand)
├── storage/             cache/, logs/, app/ (private + public disks)
└── tests/
```

There is no `app/` directory in this skeleton at all — see
[Modules (HMVC)](modules.md). Every `make:*` generator still defaults to
`app/...` when `--module` is omitted and creates that directory itself the
moment you run it (`@mkdir(..., 0755, true)` before writing — see
[The `forge` CLI](cli.md#default-output-paths) for the exact default path
each one writes to), but prefer `--module=Name` for anything that belongs
to a specific domain, the way `Account` and `Home` do here. **Never put a
controller in `app/`** regardless: routing only ever loads
`modules/*/routes.php`, so nothing would ever route to it.

## Your first route

Add a route in a module's `routes.php` (or generate a whole new module with
`php forge make:module Blog`):

```php
// modules/Home/routes.php
$router->get('/hello/{name}', function (\Marrow\Http\Request $request, string $name) {
    return "Hello, {$name}!";
});
```

Or add a controller method — see [Routing](routing.md) and
[Requests & Responses](http.md).

## Companion packages

Beyond the framework core, a few official packages extend it — each one an
independent, self-documented Composer package, not something bundled in:

```bash
composer require marrow/form-builder        # Django-style backend forms
composer require --dev marrow/anvil          # Docker Compose dev environment
composer require --dev marrow/compass         # generates AGENTS.md for AI coding agents
```

All three register themselves automatically on install (auto-discovery — see
[Modules (HMVC)](modules.md#distributing-a-module-as-a-package)), no manual
`config/modules.php` edit needed.

## Where to go next

- [Architecture & Request Lifecycle](architecture.md) — how a request actually flows through the framework
- [Modules (HMVC)](modules.md) — the unit of organisation for real applications
- [The `forge` CLI](cli.md) — the full list of `make:*` generators and other commands
- [Authentication & RBAC](authentication.md) — guards, Gate, policies, roles/permissions, 2FA
