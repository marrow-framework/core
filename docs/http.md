# Requests & Responses

## Request

`Marrow\Http\Request` extends
`Symfony\Component\HttpFoundation\Request`, adding:

```php
$request->wantsJson();              // Accept header / X-Requested-With
$request->bearerToken();            // Authorization: Bearer ...
$request->input('title');           // query + body, merged
$request->all();
$request->only(['title', 'body']);
$request->except(['password']);
$request->has('title');
$request->json();                   // decoded JSON body as array
$request->file('avatar');           // Http\UploadedFile|null
$request->hasFile('avatar');
$request->allFiles();
$request->validate(['title' => 'required|string'], $customMessages);
```

`validate()` throws `Validation\ValidationException` on failure — see
[Validation](validation.md). Method spoofing via a hidden `_method` field
(`PUT`/`PATCH`/`DELETE`) is supported for HTML forms, which can only submit
`GET`/`POST`.

## Response types

| Class | Use |
|---|---|
| `Http\Response` | HTML/plain body; static factories `Response::view()`, `::json()`, `::redirect()`, `::render()`, `::text()`, `::noContent()` |
| `Http\JsonResponse` | JSON body, correct `Content-Type` |
| `Http\RedirectResponse` | `Location` header + status |

The static factories on `Response` are an intentional escape hatch for
contexts without dependency injection (closures, one-off CLI output) — from
inside a `Controller`, prefer the instance helpers below, which also carry
the current request/router context.

## `Controller` helpers

Every controller extending `Http\Controller` gets `TemplateEngine`,
`Router`, `Gate`, and the current `Request` constructor-injected
automatically (the `Router` resolves controllers through the `Container`).
A subclass adding its own dependencies must forward these four via
`parent::__construct(...)`.

```php
class PostController extends Controller
{
    protected function view(string $template, array $data = []): Response;
    protected function json(mixed $data = [], int $status = 200, array $headers = []): JsonResponse;
    protected function redirect(string $url, int $status = 302): RedirectResponse;
    protected function redirectToRoute(string $name, array $params = [], int $status = 302): RedirectResponse;
    protected function back(int $status = 302): RedirectResponse;          // Referer, falls back to '/'

    protected function validate(Request $request, array $rules, array $messages = []): array;

    protected function authorize(string $ability, mixed $arguments = []): void;   // throws 403
    protected function can(string $ability, mixed $arguments = []): bool;
    protected function cannot(string $ability, mixed $arguments = []): bool;

    protected function bouncer(string $policyClass): PolicyGate;                 // explicit-policy authorization
    protected function authorizePolicy(string $policyClass, string $ability, mixed ...$arguments): void;
}
```

See [Authentication & RBAC](authentication.md) for `authorize`/`can`/`bouncer`.

A controller can also declare its own middleware for the router/group layer
to read:

```php
class AdminController extends Controller
{
    protected array $middleware = ['auth', 'throttle:60,1'];
}
```

## `ApiController` — JSON-first controllers

```php
class PostApiController extends ApiController
{
    public function index(): JsonResponse
    {
        return $this->ok($posts);                 // 200 { "ok": true, "data": [...] }
    }

    public function store(StorePostRequest $r): JsonResponse
    {
        return $this->created($post);              // 201
    }

    public function index2(): JsonResponse
    {
        return $this->paginate($items, $total, perPage: 20, page: $page);
        // 200 { "ok": true, "data": [...], "meta": {...}, "links": {...} }
        // + X-Total-Count / X-Page / X-Per-Page headers
    }

    public function show(int $id): JsonResponse
    {
        $post = Post::find($id);
        return $post ? $this->ok($post) : $this->notFound();   // 404
    }
}
```

Full list: `ok()`, `created()`, `accepted()`, `noContent()`, `paginate()`,
`notFound()`, `unauthorized()`, `forbidden()`, `unprocessable($errors)`,
`error($message, $status, $extra)`. Every success payload is wrapped as
`{"ok": true, "data": ...}` (plus `meta` when given); every error payload as
`{"ok": false, "message": ..., ...}`.

`ok()`/`created()`/`paginate()` auto-serialize their `$data`:
`Http\Resources\JsonResource`/`ResourceCollection` via `toArray($request)`,
any object with a `toArray()` method, arrays recursively — otherwise the
value passes through as-is.

## `FormRequest` — typed, auto-validated input

```php
class StorePostRequest extends FormRequest
{
    public function rules(): array
    {
        return ['title' => 'required|string|max:255', 'body' => 'required|string'];
    }

    public function authorize(): bool
    {
        return true; // return false to abort with 403 before validation even runs
    }
}

class PostController extends Controller
{
    public function store(StorePostRequest $request): Response
    {
        Post::create($request->validated());
        return $this->redirectToRoute('posts.index');
    }
}
```

The `Router` calls `validateResolved()` automatically the moment it injects
a `FormRequest` subclass into a controller action — by the time your method
body runs, `$request->validated()` is already available and guaranteed
valid. Override `messages()` for custom error text, `prepareForValidation()`
to normalize input before rules run (e.g. `$this->merge([...])`), or
`passedValidation()` for post-validation side effects.

## File uploads

```php
$request->hasFile('avatar');
$file = $request->file('avatar');       // Http\UploadedFile
$file->isValid();
$file->getClientOriginalExtension();
$file->getMimeType();
$file->getSize();
$file->dimensions();                    // [width, height] for images, or null
```

Combine with the file-aware validation rules (`file`, `image`, `mimes:...`,
`mime_types:...`, `max:2048` in KB, `dimensions:min_width=100,...`) — see
[Validation](validation.md).

## Exceptions instead of manual status juggling

```php
abort(404, 'Post not found');
abort_if($post->author_id !== auth()->id(), 403);
abort_unless($request->has('title'), 422, 'Title is required');
```

These throw `Exceptions\HttpException`, which `Exceptions\Handler` renders
as a JSON error envelope, a Twig error template, or the interactive debug
page depending on the request type and `app.debug` — see
[Security Hardening](security.md#error-handling).
