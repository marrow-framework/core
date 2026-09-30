# Marrow Framework

![Marrow](https://github.com/AureDulvresse/marrow/blob/main/logo.png)

The technical core of Marrow: dependency container, HMVC modules, HTTP routing, CLI, ORM, security, and application services.

[![CI](https://img.shields.io/github/actions/workflow/status/marrow/framework/ci.yml?branch=main&style=flat-square&label=CI)](https://github.com/marrow/framework/actions/workflows/ci.yml)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net)
[![License MIT](https://img.shields.io/badge/license-MIT-22c55e?style=flat-square)](LICENSE)
[![Version](https://img.shields.io/badge/version-2.3.0-f97316?style=flat-square)](https://github.com/marrow/framework/releases)

This repository contains the framework core. It is not a ready-to-use application project: for starting an application, it is recommended to use the associated skeleton repository.

## Table of contents

- [About](#about)
- [Requirements](#requirements)
- [Installation](#installation)
- [Framework architecture](#framework-architecture)
- [Container and dependency injection](#container-and-dependency-injection)
- [Modules](#modules)
- [HTTP routing](#http-routing)
- [ORM and database](#orm-and-database)
- [Security and middleware](#security-and-middleware)
- [CLI](#cli)
- [Health checks](#health-checks)
- [Package ecosystem](#package-ecosystem)
- [Repository structure](#repository-structure)
- [Testing and quality](#testing-and-quality)
- [Contributing](#contributing)

---

## About

Marrow is a PHP 8.2+ framework designed for modular, scalable, and testable applications. The project core includes the following building blocks:

- dependency container with automatic resolution via reflection;
- module system with explicit dependencies;
- HTTP kernel and console kernel;
- router with groups, named routes, and middleware;
- lightweight ORM based on models, relationships, and migrations;
- authentication and authorization system;
- Twig template engine;
- event handling, jobs, queue workers, and scheduling;
- pluggable health checks (database, cache, disk, queue) aggregated into a single `/health` report.

The framework follows a modern architectural direction: clear separation of concerns, explicit dependencies, isolated modules, and service injection instead of global resolution.

---

## Requirements

- PHP 8.2 or newer
- Composer 2+
- Common extensions: `pdo`, `mbstring`, `json`
- An application project using the recommended skeleton

> The framework core is distributed as a Composer package. The skeleton remains the simplest way to bootstrap a complete and coherent application.

---

## Installation

### Recommended option: use the skeleton

```bash
composer create-project marrow/skeleton mon-app
```

### Direct package option

```bash
composer require marrow/framework
```

---

## Framework architecture

The core is structured around a few central classes:

- `Application`: application runtime entry point; loads the environment, initializes base services, and boots modules;
- `Container`: IoC container with binding, singleton support, automatic instantiation, and inter-module access validation;
- `ModuleManager`: registers modules, validates imports, sorts them topologically, and triggers the `register()` and `boot()` phases;
- `Router`: manages HTTP routes, middleware, groups, and resources;
- `Kernel`: HTTP and console kernel;
- `Model`: base model class for application data objects, schema access, relations, and write operations;
- `Template\Engine`: Twig rendering engine;
- `Dispatcher`: internal event bus.

### Folder structure

```text
src/
├── Application.php
├── Container.php
├── Console/
├── Database/
├── Http/
├── Module/
├── Routing/
├── Middleware/
├── Template/
├── Auth/
├── Events/
├── Queue/
├── Support/
└── ...

tests/
├── Unit/
├── Feature/
└── ...
```

---

## Container and dependency injection

Marrow relies on a dependency container that resolves services through reflection on PHP types. It supports:

- automatic resolution by type hint;
- explicit binding via `bind()` or `singleton()`;
- pre-created instances via `instance()`;
- named injection via the `#[Inject('...')]` attribute;
- access validation between modules based on declared exports.

Example:

```php
use Marrow\Attributes\Inject;
use Marrow\Events\Dispatcher;

class PostService
{
    public function __construct(
        private readonly PostRepository $posts,
        private readonly Dispatcher $events,
        #[Inject('config.app.name')] private readonly string $appName,
    ) {
    }
}
```

The container also handles transitive dependencies and contextual resolution based on the calling module, which helps protect access to module providers.

---

## Modules

A module is the main building block of Marrow. Each module is declared via the `#[Module]` attribute and has a two-phase lifecycle:

1. `register()`: register bindings and module services;
2. `boot()`: load routes, Twig namespaces, event listeners, and other runtime elements.

Example:

```php
use Marrow\Module\Attributes\Module;
use Marrow\Module\BaseModule;

#[Module(
    name: 'blog',
    imports: [AuthModule::class],
    providers: [PostService::class],
    exports: [PostService::class],
    listeners: [
        PostPublished::class => [SendNewsletterListener::class],
    ],
)]
class BlogModule extends BaseModule
{
}
```

### Module validation

The `ModuleManager` validates:

- missing dependencies (`imports` not registered);
- dependency cycles;
- boot order using topological sorting.

This ensures a module only starts when its dependencies are correctly declared and resolvable.

---

## HTTP routing

Marrow’s router is fluent and framework-oriented. It supports:

- standard HTTP methods (`GET`, `POST`, `PUT`, `PATCH`, `DELETE`);
- route groups with `prefix`, `middleware`, and `namespace`;
- REST resource management;
- URL generation by route name;
- controller parameter resolution and HTTP request handling.

Example:

```php
$router->group(['prefix' => '/admin', 'middleware' => ['auth']], function () use ($router) {
    $router->get('/posts', [PostController::class, 'index'])->name('posts.index');
    $router->post('/posts', [PostController::class, 'store'])->name('posts.store');
});
```

Routing also relies on the container to instantiate controllers and inject the required dependencies.

`Route` also exposes two chainable shortcuts for the two most common middleware cases:

```php
$router->get('/dashboard', [DashboardController::class, 'index'])->auth();
$router->post('/login', [AuthController::class, 'login'])->throttle(5, 1); // 5 attempts / minute
```

A module doesn't have to live under the application's local `modules/` directory either — see [Package ecosystem](#package-ecosystem).

---

## ORM and database

Marrow includes a model-oriented ORM without exposing a generic query builder as the primary design layer. Model classes can define attributes, casts, relationships, and scopes.

Example:

```php
class Post extends Model
{
    protected string $table = 'posts';

    protected array $fillable = ['title', 'body', 'author_id'];

    protected array $casts = [
        'published_at' => 'datetime',
        'metadata' => 'json',
    ];
}
```

Included features:

- relationships such as `hasMany`, `belongsTo`, `belongsToMany`, `hasOne`, and `hasManyThrough`;
- query scopes;
- eager loading;
- migrations;
- soft deletes;
- support for PHP enums when applicable.

---

## Security and middleware

The framework exposes a shared middleware pipeline for HTTP requests. Middlewares can be declared in a classic style (`handle`) or using a hook pattern (`processRequest`, `processResponse`, `processException`).

This enables handling:

- authentication;
- CORS;
- CSRF;
- data sanitization;
- sessions;
- throttling;
- HTTP header protection.

Security configuration is typed through `ShieldConfig`, and HTTP concerns are separated from application logic to keep the system more readable and maintainable.

---

## CLI

The core includes a set of Symfony Console commands useful for generating modules, managing migrations, inspecting routes, and starting the development server.

Typical commands:

```bash
php forge list
php forge serve
php forge make:module Blog
php forge make:controller PostController --module=Blog --resource
php forge make:model Post --module=Blog --migration
php forge route:list
php forge module:graph
php forge migrate
php forge migrate --fresh --seed
```

The command system is extensible and can also register module-specific commands.

---

## Health checks

`Health\HealthManager` aggregates pluggable probes (database, cache, disk space, queue backlog) into one report, meant to be exposed behind a `/health` route for load balancers, uptime monitors, or container orchestrators:

```json
{
  "status": "ok",
  "checks": {
    "database": {"status": "ok", "message": "OK", "duration_ms": 1.2},
    "queue": {"status": "ok", "message": "OK", "duration_ms": 0.4}
  },
  "duration_ms": 4.21
}
```

Thresholds are configured per check in `config/health.php`. Writing a custom check is a two-method interface (`name()`, `run(): HealthResult`) — see the full guide in `docs/health.md`.

---

## Package ecosystem

A module isn't limited to the application's local `modules/` directory: `BaseModule::path()` resolves `Views/`, `routes.php`, and migrations by reflecting on the module class's own file location, so a module ships just as well from `vendor/`. Installing a package that declares its module in its own `composer.json` (`extra.marrow.modules`) is enough on its own — `PackageDiscovery` picks it up automatically at boot, no edit to `config/modules.php` required.

Official companion packages built on this mechanism, each an independent, self-documented Composer package (own `README.md`/`LICENSE`, installable on its own):

| Package | Purpose |
|---|---|
| [`marrow/form-builder`](https://github.com/marrow/form-builder) | Django-style declarative forms — define fields on the backend, render and validate them without duplicating rules on the frontend |
| [`marrow/anvil`](https://github.com/marrow/anvil) | Local Docker Compose development environment (Sail's role, under its own name) |
| [`marrow/compass`](https://github.com/marrow/compass) | Generates `AGENTS.md` — a live map of routes/modules/config for AI coding agents and new contributors |

```bash
composer require marrow/form-builder
composer require --dev marrow/anvil marrow/compass
```

See [Modules (HMVC)](docs/modules.md#distributing-a-module-as-a-package) for how the discovery mechanism itself works, if you want to ship your own.

---

## Repository structure

```text
.
├── CHANGELOG.md
├── composer.json
├── LICENSE
├── phpstan.neon
├── phpunit.xml
├── README.md
├── src/
├── tests/
└── vendor/
```

This repository is a framework package. For a complete application project, it is preferable to use the corresponding skeleton, which provides the base runtime structure, modules, and configuration files.

---

## Testing and quality

The project is configured for automated validation:

```bash
composer test
composer analyse
```

The scripts are defined in `composer.json`, including:

- `pest` for tests;
- `phpstan` for static analysis;
- a quality standard consistent with a modern PHP framework core.

---

## Contributing

Contributions are welcome in line with the project conventions:

- PHP 8.2+ code with strict typing;
- PSR-12 and coherent conventions;
- tests for functional changes;
- respect for compatibility and versioning semantics;
- explicit PRs with a clear description of changes.

### Recommended workflow

```bash
git clone https://github.com/marrow/framework.git
cd framework
composer install
composer test
```

Then open a PR with a concise description of the feature or bug fix.

---

Marrow aims to provide a solid foundation for modular, testable, and maintainable PHP applications without relying on excessive abstractions or opaque conventions.
