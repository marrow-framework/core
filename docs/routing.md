# Routing

`Marrow\Routing\Router` (`framework/src/Routing/Router.php`) is a fluent
router with groups, resource routes, named routes, middleware aliases, and
automatic controller-method dependency injection.

## Defining routes

```php
$router->get('/posts', [PostController::class, 'index']);
$router->post('/posts', [PostController::class, 'store']);
$router->put('/posts/{id}', [PostController::class, 'update']);
$router->patch('/posts/{id}', [PostController::class, 'update']);
$router->delete('/posts/{id}', [PostController::class, 'destroy']);
$router->any('/webhook', WebhookController::class . '@handle');
$router->match(['GET', 'HEAD'], '/ping', fn () => 'pong');
```

An action can be:

- `[ControllerClass::class, 'method']` — resolved through the container
- `'ControllerClass@method'` — string form of the same
- a closure — receives `(Request $request, ...$routeParams)`

### Named routes and URL generation

```php
$router->get('/posts/{id}', [PostController::class, 'show'])->name('posts.show');

route('posts.show', ['id' => 42]);        // helper
$router->route('posts.show', ['id' => 42]); // equivalent
```

A missing route name throws `RuntimeException` — there's no silent fallback.

### Resource routes

```php
$router->resource('posts', PostController::class);
```

Registers the same seven conventional routes as Laravel's `Route::resource`:
`index`, `create`, `store`, `show`, `edit`, `update` (both `PUT`/`PATCH`),
`destroy` — named `posts.index`, `posts.show`, etc.

## Groups

```php
$router->group(['prefix' => '/admin', 'middleware' => ['auth', 'throttle:120,1']], function () use ($router) {
    $router->get('/dashboard', [DashboardController::class, 'index']);
});
```

Group attributes: `prefix` (concatenated across nested groups), `middleware`
(applied to every route added inside the group, merged with the route's
own), `namespace` (prepended to a `[Class, method]` action's class name).

> Watch for the empty-path index route inside a prefixed group —
> `$router->get('', ...)` under `prefix: '/admin'` registers as `/admin/`
> while `route()` generates `/admin` (trailing slash trimmed), which is a
> 404 mismatch. Declare such index routes with an explicit path
> (`$router->get('/admin', ...)`) instead of relying on an empty group path.

## Middleware resolution

Both the `Router` (per-route/group) and `Http\Kernel` (global stack) expand
middleware references through the exact same
`Middleware\MiddlewareResolver`, configured from `config/middleware.php`'s
`aliases` and `groups`:

```php
->middleware('auth')            // alias → class
->middleware('throttle:60,1')   // alias with params, preserved verbatim
->middleware('web')             // group → expands recursively
```

See [Middleware](middleware.md) for the resolver's exact expansion rules and
the built-in middleware catalogue.

### `auth()` / `throttle()` shortcuts

`Route` also has two named, chainable shortcuts for the two most common
cases, equivalent to the `->middleware(...)` calls above but harder to
typo:

```php
$router->get('/dashboard', [DashboardController::class, 'index'])
    ->name('dashboard')
    ->auth();                 // -> middleware('auth')

$router->get('/api/me', [ProfileController::class, 'show'])
    ->auth('jwt');            // -> middleware('auth:jwt')

$router->post('/login', [AuthController::class, 'login'])
    ->throttle(5, 1);         // -> middleware('throttle:5,1'), 5 attempts / minute
```

They chain freely with `name()`, `middleware()`, and each other, in any
order:

```php
$router->post('/login', [AuthController::class, 'login'])
    ->name('login')
    ->throttle(5, 1)
    ->middleware('sanitize');
```

## Controller method parameter resolution

`Router::callAction()` resolves each controller method parameter, in order:

1. A parameter type-hinted with a `FormRequest` subclass — built from the
   current request via `createFrom()` and auto-validated
   (`validateResolved()`) **before** the action runs, so a failing
   validation never reaches your method body.
2. A parameter type-hinted `Request` (or a subclass) — the current request.
3. Any other non-builtin class type-hint — resolved via the container
   (`$container->make($typeName)`), so you can inject services directly
   into an action method, not just the controller constructor.
4. A name matching a route parameter (`{id}` → `int|string $id`) — route
   parameters always arrive as **strings**; don't type-hint a route
   parameter `int` expecting automatic coercion, use `int|string` (or cast
   manually) if you need to branch on the numeric value.
5. An optional parameter's default value.
6. A nullable parameter → `null`.
7. Otherwise, `RuntimeException` naming the unresolvable parameter.

```php
class PostController extends Controller
{
    public function update(UpdatePostRequest $request, int|string $id, PostService $posts): Response
    {
        $posts->update($id, $request->validated());
        return $this->redirectToRoute('posts.show', ['id' => $id]);
    }
}
```

## Closures as actions

```php
$router->get('/status', function (\Marrow\Http\Request $request) {
    return ['status' => 'ok'];
});
```

Closures only receive `(Request $request, ...$routeParams)` positionally —
no container-based parameter resolution beyond that (unlike controller
methods). Return arrays/objects to get an automatic `JsonResponse`, a
scalar for a plain HTML `Response`, or build a `Response` yourself.

## `#[Route]` attribute routing

An alternative to writing `$router->get(...)` by hand for each action —
declare the route on the controller method itself:

```php
use Marrow\Routing\Attributes\Route;

#[Route('/posts', middleware: 'web')]
class PostController extends Controller
{
    #[Route('/', name: 'posts.index')]
    public function index(): Response { /* ... */ }

    #[Route('/{id}', method: 'POST', name: 'posts.update', middleware: 'auth')]
    public function update(int|string $id): Response { /* ... */ }
}
```

Then register the whole controller from `routes.php`, same as any other
route — this only changes where a route's metadata lives, not Marrow's
routing-is-module-only convention (see [Modules](modules.md)):

```php
// modules/Blog/routes.php
$router->controller(PostController::class);
```

A class-level `#[Route]` supplies a URI prefix and middleware shared by
every attributed method (`name`/`method` are ignored there). `method`
accepts a single verb or an array (`method: ['GET', 'HEAD']`) to register
the same handler under more than one. `controller()` goes through the same
`addRoute()` as `get()`/`post()`/etc., so it honors the current `group()`
prefix and middleware exactly the same way:

```php
$router->group(['prefix' => '/admin'], function () use ($router) {
    $router->controller(PostController::class); // -> /admin/posts/...
});
```

A class-level `#[Route]` is repeatable — stack more than one to register the
same controller under multiple prefixes (e.g. a legacy alias):

```php
#[Route('/posts')]
#[Route('/articles')]  // both prefixes registered, not just the first
class PostController extends Controller { /* ... */ }
```

Each attributed method is then registered once per class-level prefix. A
fixed `name:` on a method resolves, via `route()`, to whichever
registration was added last if reused across more than one prefix this
way — give each prefix's registration a distinct name if you need both
reachable by name.

## Loading routes

Routes live in each module's `routes.php`, loaded automatically by
`ModuleManager` during boot (see [Modules](modules.md#routes)) via:

```php
public function loadRoutes(string $routesFile): void
{
    if (is_file($routesFile)) {
        $router = $this->getRouter();
        require $routesFile;
    }
}
```

You can call `$router->loadRoutesFrom($file)` yourself from anywhere with
access to the `Router` instance if you need a route file outside the module
convention.

## Introspection

```bash
php forge route:list
```
