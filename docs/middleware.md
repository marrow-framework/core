# Middleware

## Two supported styles

`Middleware\Pipeline` accepts middleware written in either style, detected
by which methods the class defines:

**Onion-style** (PSR-15-flavoured):

```php
class MyMiddleware
{
    public function handle(Request $request, callable $next, ...$params): Response
    {
        // before
        $response = $next($request);
        // after
        return $response;
    }
}
```

**Hook-style** (Django-flavoured) — implement any subset of three hooks:

```php
class MyMiddleware
{
    public function processRequest(Request $request, ...$params): ?Response
    {
        // return a Response to short-circuit the chain entirely; null to continue
    }

    public function processResponse(Request $request, Response $response, ...$params): Response
    {
        return $response;
    }

    public function processException(Request $request, \Throwable $e, ...$params): ?Response
    {
        // return a Response to recover; null (or omit this hook) to keep propagating
    }
}
```

`processException` only fires for something *further down* the chain
(an inner middleware or the destination) throwing — a recovered response
still passes back through this same middleware's own `processResponse()`.

A middleware with none of these methods throws `RuntimeException` the first
time the pipeline tries to run it.

## Pipe syntax

Whatever produces the middleware list (route/group `middleware`, or
`config/middleware.php`'s `global`), each entry is one of:

- `'App\Middleware\Foo'` — resolved via the container, no params
- `'App\Middleware\Foo:a,b'` — resolved via the container, `$params = ['a', 'b']`
- an already-instantiated object — used as-is

## Aliases and groups (`config/middleware.php`)

```php
return [
    'global' => [
        MaintenanceMode::class,
        SecurityHeaders::class,
        RequestLogger::class,
    ],
    'aliases' => [
        'auth' => Authenticate::class,
        'throttle' => ThrottleRequests::class,
        'cors' => HandleCors::class,
        // ...
    ],
    'groups' => [
        'web' => ['session', 'flash-errors', 'trim', 'sanitize', 'csrf'],
        'api' => ['sanitize', 'cors', 'throttle:60,1'],
    ],
];
```

`Middleware\MiddlewareResolver` expands a reference in this order: strip an
optional `:params` suffix → if the name matches a **group**, recursively
expand its members (cycle-guarded) → otherwise map through **aliases** (or
pass a raw class name through unchanged) → reattach `:params` if present.
`Http\Kernel` (global stack) and `Routing\Router` (route/group stack) share
the exact same resolver instance semantics, so an alias or group behaves
identically no matter where it's applied.

```php
$router->get('/admin', Controller::class . '@index')->middleware(['web', 'auth']);
```

## Built-in middleware catalogue

| Class | What it does |
|---|---|
| `Authenticate` | 401 (JSON) or 302 if the given guard (default `session`) has no authenticated user |
| `RedirectIfAuthenticated` | Inverse — redirects to `/` if already authenticated (guest-only routes) |
| `HandleCors` | `Access-Control-*` headers from `config/cors.php`; answers `OPTIONS` preflight directly |
| `VerifyCsrfToken` | 419 on state-changing requests missing/mismatching the CSRF token; exempt via `config/shield.php → csrf_except` |
| `SecurityHeaders` | `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, COOP/CORP, HSTS (HTTPS only), optional CSP — all from `config/shield.php` |
| `SanitizeInput` | Strips null bytes, truncates strings over 65,535 chars (skips password/token fields) |
| `TrimStrings` | Trims whitespace from body input (skips password fields) |
| `ThrottleRequests` | Sliding-window rate limiting; `throttle:maxAttempts,decayMinutes`; 429 + `Retry-After`/`X-RateLimit-*` |
| `StartSession` | Starts the session, flashes pending `RedirectResponse` data, saves on the way out |
| `ShareErrorsFromSession` | Pulls `_errors`/`_old_input` flashed by a failed validation redirect into Twig globals |
| `MaintenanceMode` | 503 for every request while `storage/maintenance.flag` exists, unless a matching bypass cookie is present |
| `RequestLogger` | Logs `METHOD /uri → STATUS (TIMEms)`; `php forge serve` parses this exact line to render its colourised output |
| `HotReloadMiddleware` | Dev-only (`APP_ENV=local` or `APP_DEBUG=true`); serves `/__marrow/ping` and injects a polling script into HTML responses |

`SecurityHeaders` and `VerifyCsrfToken` both read the same `Http\Shield\ShieldConfig`
— see [Security Hardening](security.md) for the full "Shield" bundle.

## Global vs. route/group middleware

- **Global** (`config/middleware.php → 'global'`) runs on every request,
  before routing — appropriate for things that must apply unconditionally
  (security headers, maintenance mode, request logging).
- **Route/group** middleware runs only for matched routes, after the global
  stack, and can differ per route (`web` vs `api` groups, `auth` only on
  protected routes, etc.).

`HotReloadMiddleware` is prepended to the global stack automatically by
`Http\Kernel` in dev mode — never list it in `config/middleware.php` yourself.

## Writing your own

```php
namespace App\Middleware;

use Marrow\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    public function handle(Request $request, callable $next): Response
    {
        $user = $request->attributes->get('auth_user');
        if (!$user || !$user->hasRole('admin')) {
            abort(403);
        }
        return $next($request);
    }
}
```

```bash
php forge make:middleware EnsureAdmin
```

Then register an alias in `config/middleware.php` and apply it with
`->middleware('admin')`, or reference the FQCN directly without an alias.

### Typed parameters — a real pitfall

A `'name:a,b'` reference's params are split on `,` by
`MiddlewareResolver`/`Pipeline::resolve()` and always arrive as **strings**
— there's no numeric coercion anywhere upstream. A `handle()` parameter
typed as a plain `int`/`bool` will throw a `TypeError` the moment the
middleware is actually used with a route (this was a real, previously
undetected bug in the framework's own `ThrottleRequests`, which typed
`int $maxAttempts` and 500'd on every request to a `throttle:N,M` route
until fixed). Type such parameters `string|int` and cast internally:

```php
public function handle(Request $request, callable $next, string|int $times = 3): Response
{
    $times = (int) $times;
    // ...
}
```
