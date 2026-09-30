# Security Hardening

Marrow bundles its security-related middleware under a single config
source, `config/shield.php`, read into one typed `Http\Shield\ShieldConfig`
object (constructor-injected into `SecurityHeaders` and `VerifyCsrfToken`)
— the same idea as AdonisJS's Shield.

## Security headers & CSP

```php
// config/shield.php
return [
    'headers' => [
        // 'X-Powered-By' => false,  // omit a default header
    ],
    'hsts' => ['max_age' => 31536000, 'include_subdomains' => true, 'preload' => false],
    'csp' => [
        'enabled' => false,
        'preset' => 'strict',     // 'strict' | 'relaxed' | anything else = empty base policy
        'report_only' => false,
        'directives' => [
            // 'script-src' => ["'self'", 'https://cdn.example.com'],
        ],
    ],
];
```

`SecurityHeaders` middleware sends, by default: `X-Frame-Options: SAMEORIGIN`,
`X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`,
`Permissions-Policy: camera=(), microphone=(), geolocation=()`,
`Cross-Origin-Opener-Policy`/`Cross-Origin-Resource-Policy: same-origin`.
Set any of these `false` in `headers` to omit it, or add your own. HSTS is
only sent on HTTPS requests, and only if `max_age > 0`. CSP is off by
default (`csp.enabled = false`) — enable it deliberately once you've
audited your inline scripts/styles, starting with `report_only: true`.

## CSRF protection

`VerifyCsrfToken` rejects any state-changing request (not `GET`/`HEAD`/`OPTIONS`)
whose `_token` field / `X-CSRF-TOKEN` / `X-XSRF-TOKEN` header doesn't match
the session's token, with a `419` `HttpException`.

```twig
<form method="POST" action="/posts">
    {{ csrf_field() }}
    ...
</form>
```

```js
fetch('/api/posts', { headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' }, method: 'POST' });
```

Exempt specific paths (webhooks, bearer-token APIs) via `fnmatch` patterns:

```php
// config/shield.php
'csrf_except' => ['webhooks/*', 'api/*'],
```

`SessionManager::csrfToken()` throws if called before `StartSession` has
run — a silent empty-string fallback there would let an empty submitted
`_token` pass `hash_equals('', '')`, a real bypass. Make sure `session`
(→ `StartSession`) precedes `csrf` in whatever middleware group protects
your forms — the skeleton's `web` group already orders them correctly.

## CORS

```php
// config/cors.php
return [
    'allowed_origins' => ['https://app.example.com'],  // or ['*'], or wildcard patterns
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_headers' => ['Content-Type', 'Authorization', ...],
    'exposed_headers' => ['X-RateLimit-Limit', ...],
    'supports_credentials' => false,   // true requires an exact origin list, not '*'
    'max_age' => 86400,
];
```

Apply `HandleCors` (aliased `cors`) to whichever routes/groups need it —
the skeleton's `api` middleware group includes it by default. `OPTIONS`
preflight requests are answered immediately with a `204`, before the rest
of the pipeline runs.

## Rate limiting

```php
$router->post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
```

`ThrottleRequests` (`RateLimiting\RateLimiter`) uses a **sliding window**
(individual hit timestamps, cache-backed) rather than a fixed-window
counter — a client can't burst through 2× the limit across a window
boundary the way a naive fixed-window counter would allow. Keyed by client
IP + route path, or by `user:{id}` when `$request->attributes->get('auth_user')`
is set, so clients behind shared NAT don't exhaust each other's quota.
Exceeding the limit responds `429` with `Retry-After` and
`X-RateLimit-Limit`/`X-RateLimit-Remaining` headers; successful responses
still carry the last two.

## Input sanitization

- `SanitizeInput` — strips null bytes, truncates any string field over
  65,535 characters (skips `password`/`_token`-like fields). This is
  defense-in-depth, **not** HTML escaping — Twig auto-escapes output by
  default, which is what actually prevents stored XSS in templates.
- `TrimStrings` — trims whitespace from body input (same field exclusions).

## Encryption

`Support\Crypto` provides AES-256-GCM (authenticated) encryption, keyed
from `APP_KEY` (SHA-256-derived, so any non-empty `APP_KEY` works
regardless of its raw length):

```php
$encrypted = \Marrow\Support\Crypto::encrypt($plaintext);
$plaintext = \Marrow\Support\Crypto::decrypt($encrypted);   // '' if tampered/malformed/wrong key
```

Both throw `RuntimeException` if `APP_KEY` isn't set — there is deliberately
no plaintext fallback. This backs the `encrypted` model cast and
`HasTwoFactor`'s secret storage — see [Database & ORM](database.md#casts)
and [Authentication & RBAC](authentication.md#two-factor-authentication).

## Password hashing

Argon2id via `Auth\Hash` — see
[Authentication & RBAC](authentication.md#password-hashing). Login attempts
always run a real hash verification even for a non-existent account, to
avoid a timing side-channel revealing which emails are registered.

## Maintenance mode

```bash
php forge down                        # writes storage/maintenance.flag
php forge down --secret=letmein       # allow bypass via a cookie
php forge up                          # removes the flag
```

While the flag file exists, `MaintenanceMode` middleware returns `503` for
every request except one carrying a `maintenance_bypass` cookie matching
the configured secret.

## Error handling

`Exceptions\Handler` renders uncaught exceptions:

- `Validation\ValidationException` → `422` JSON, or a redirect back with
  `_errors`/`_old_input` flashed to the session (see
  [Validation](validation.md)).
- A JSON request (`$request->wantsJson()`) → `{"error": ..., "status": ...}`,
  plus a truncated stack trace when `app.debug` is true.
- `app.debug = true` → an interactive, dark-themed debug page (inline CSS
  and JS only, no CDN dependency) with a clickable stack frame list, source
  snippets, and request/header inspection.
- Otherwise → a Twig error template, `errors/{status}.html.twig` if your
  application defines one, else the framework's own bundled fallback
  (`@core_errors/{status}.html.twig`, covering 403/404/419/429/500/503).

**Never enable `APP_DEBUG` in production** — the debug page includes
request headers, body parameters, and source file contents.

## Supply-chain hygiene

The framework's own CI (`composer audit`) checks for known vulnerable
dependencies on every push. Run the same check in your application:

```bash
composer audit
```
