# Service Container

`Marrow\Container` (`framework/src/Container.php`) is the IoC container
backing everything else in the framework: controllers, middleware, console
commands, and module `providers` are all resolved through it.

## Registering bindings

```php
$container->bind(Mailer::class, fn (Container $c) => Mailer::fromDsn(...));
$container->singleton(CacheManager::class, fn () => new CacheManager(...));
$container->instance(Application::class, $this);
```

- `bind($abstract, $concrete, singleton: false, module: null)` — `$concrete`
  can be a factory closure `fn(Container $c) => ...` or another class name
  to alias to (`bind(LoggerInterface::class, Logger::class)`).
- `singleton()` is `bind(..., singleton: true)` — the factory runs once, the
  result is cached and reused for every subsequent `make()`.
- `instance($abstract, $object)` registers an already-built object (used for
  `Application`, `Container` itself, and the current `Request`).

## Resolving

```php
$mailer = $container->make(Mailer::class);
$mailer = $container->make(Mailer::class, overrides: ['config' => $customConfig]);
```

`make()`:

1. Returns a cached instance immediately if one exists.
2. Otherwise runs the registered factory (if bound) — reflection-based
   auto-resolution is skipped for anything explicitly bound.
3. Falls back to reflecting the class's constructor and resolving each
   parameter, in order:
   1. an override matching the parameter **name**
   2. an override matching the parameter's **class type**
   3. a `#[Inject('key')]` attribute on the parameter
   4. the parameter's class type-hint, resolved recursively via `make()`
   5. the parameter's default value, if optional
   6. otherwise a `ContainerException`

Global helper: `app(SomeClass::class)` is shorthand for
`Application::getInstance()->getContainer()->make(SomeClass::class)`; `app()`
with no argument returns the `Application` instance itself.

## `#[Inject]` — attribute-based injection

```php
use Marrow\Attributes\Inject;

class ReportService
{
    public function __construct(
        #[Inject('config.app.name')] private string $appName,
        #[Inject('billing.stripe_client')] private StripeClient $stripe,
    ) {}
}
```

A key prefixed `config.` resolves through `Config\Repository::get()` (dot
notation after the prefix). Any other key is looked up as a named binding
(`$container->has($key)` must be true) — useful for binding a value under a
string key rather than a class name.

## Module isolation

Every binding can track an **owner module** (the FQCN of the `#[Module]`
class that registered it). `ModuleManager::boot()` sets this automatically
for a module's declared `providers`, and for anything a module `exports`
(see [Modules (HMVC)](modules.md)).

When code belonging to module `A` resolves a binding owned by module `B`,
the container checks whether `B` explicitly listed that binding in its
`exports`:

```php
#[Module(
    name: 'billing',
    providers: [StripeClient::class, InvoiceService::class], // private by default
    exports: [InvoiceService::class],                        // importers may use this one
)]
```

If module `Reporting` imports `Billing` and tries to resolve
`StripeClient::class` (not exported), it gets a `ContainerException`:

```
[StripeClient] belongs to module [Modules\Billing\BillingModule] which is not exported for module [Modules\Reporting\ReportingModule].
```

This is enforced purely at `make()` time via a small "module context stack"
that survives nested/transitive resolution (so a controller inside module A
that depends on a service from module A, which itself depends on something
from module A, all correctly resolves under A's context) — it isn't a
compile-time check, so isolation bugs surface the first time the offending
code path actually runs.

### `resetModuleContext()`

A long-running process (typically a queue worker between jobs, or a
scheduler run between tasks) should call
`$container->resetModuleContext()` between units of work, so a failure
mid-resolution in one job can never leak its module context into the next
one's resolutions.

## Testing note

`Container` caches nothing statically — each `new Container()` (as used by
`ModuleManager` unit tests, or a bespoke test harness) is fully isolated, so
tests don't need to reset global state between runs.
