# Templating (Twig)

`Marrow\Template\Engine` wraps Twig — application code (and app-facing
docs) should only ever reference `Template\Engine`, never Twig's classes
directly.

## Namespaces

| Namespace | Maps to |
|---|---|
| *(root)* | `resources/views/` |
| `layouts` | `resources/views/layouts/` (auto-registered if the directory exists) |
| `errors` | `resources/views/errors/` |
| `components` | `resources/views/components/` |
| `partials` | `resources/views/partials/` |
| `emails` | `resources/views/emails/` |
| `core_errors` | the framework's own fallback error templates (`src/Exceptions/views/`) |
| `@{module}` | each enabled module's `modules/{Name}/Views/`, registered automatically at boot |

```php
return $this->view('dashboard');                 // resources/views/dashboard.html.twig
return $this->view('layouts/app');                // resources/views/layouts/app.html.twig
return $this->view('@blog/posts/index', $data);   // modules/Blog/Views/posts/index.html.twig
```

`.html.twig` is appended automatically when a template name has no
extension.

## Rendering

```php
// From a Controller:
return $this->view('@blog/posts/show', ['post' => $post]);

// Anywhere with the container:
$html = app(\Marrow\Template\Engine::class)->render('@blog/posts/show', ['post' => $post]);

// Global helper (same thing):
$html = view('@blog/posts/show', ['post' => $post]);
```

## Twig functions (`FrameworkExtension`)

| Function | Purpose |
|---|---|
| `route(name, params)` | URL for a named route |
| `asset(path)` | `public/` asset URL, cache-busted with the file's `mtime` |
| `vite_asset(entry)` | Vite dev-server URL (`public/hot` present) or manifest-resolved production path |
| `vite_dev_mode()` | `true` while the Vite dev server is running — see [Frontend Assets](frontend.md) |
| `vite_client()` | the Vite HMR bootstrap `<script>` tag, dev-mode only, `''` in production |
| `csrf_token()` / `csrf_field()` | raw token / hidden `<input>` for forms |
| `method_field(method)` | hidden `_method` input for `PUT`/`PATCH`/`DELETE` forms |
| `auth_user()` / `auth_check()` | current user / boolean |
| `config(key, default)` | reads `Config\Repository` |
| `old(key, default)` | previous input, flashed on a failed validation redirect |
| `errors(key?)` | validation errors (all, or one field's) |
| `has_error(key)` | boolean shortcut |
| `session_flash(type)` | reads + consumes a session flash value once per request (safe to call for both a check and a display) |
| `can(ability, ...args)` / `cannot(...)` | Gate check — see [Authentication & RBAC](authentication.md) |
| `gate()` | the `Gate` instance itself, for advanced use |
| `current_route()` | name of the currently dispatched route, or `null` |
| `component(name, props)` | renders a registered `Component` |
| `dump(...)` | Symfony VarDumper output |

## Filters

`truncate(length=120, suffix='…')`, `slug`, `markdown` (minimal built-in
renderer — headings/bold/italic/inline code/links/lists/paragraphs, not a
full CommonMark implementation), `time_ago` (French relative time strings),
`money(dec=',', thou=' ', symbol='€')`.

## Globals

`app.name` / `app.env` / `app.debug` / `app.version` are always available.
Share your own per-request globals from anywhere with the `Engine`:

```php
app(\Marrow\Template\Engine::class)->shareGlobal('siteName', config('app.name'));
```

## View composers

Run a callback right before any template matching a pattern renders:

```php
$engine->composer('@blog/*', function (\Marrow\Template\ViewData $view) {
    $view->set('categories', Category::all());
});
```

## Components

```php
namespace App\View\Components;

use Marrow\Template\Component;

class Alert extends Component
{
    public string $type = 'info';
    public string $message = '';

    public function render(): string
    {
        return 'components/alert'; // resources/views/components/alert.html.twig
    }
}
```

```php
app(\Marrow\Template\ComponentRegistry::class)->register('alert', Alert::class);
```

```twig
{{ component('alert', {type: 'success', message: 'Saved!'}) }}
```

By default every public, initialized, non-static property is exposed to the
template — override `data()` for computed values.

## Debug mode caveat

`strict_variables` is only enabled when `app.debug` is true. In debug mode,
`Model::__isset()` uses PHP's `isset()`, so a `null` model attribute makes
`{% if model.nullableAttr %}` **throw** rather than evaluate falsy. Prefer
the null-coalescing form in templates that might see a null attribute:

```twig
{{ model.nullableAttr ?? '' }}
```

rather than `{{ model.nullableAttr ?: 'default' }}` or a bare
`{% if model.nullableAttr %}`.
