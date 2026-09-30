# Authentication & RBAC

## Guards

`Marrow\Auth\AuthManager` manages multiple named guards, configured in
`config/auth.php`:

```php
return [
    'defaults' => ['guard' => env('AUTH_GUARD', 'session')],
    'guards' => [
        'session' => ['driver' => 'session', 'table' => 'users', 'username' => 'email'],
        'jwt' => ['driver' => 'jwt', 'table' => 'users', 'secret' => env('JWT_SECRET'), 'ttl' => 3600],
    ],
];
```

```php
$auth = app(\Marrow\Auth\AuthManager::class);

$auth->attempt(['email' => $email, 'password' => $password]);  // session guard: verifies + logs in
$auth->check();
$auth->user();
$auth->id();
$auth->login($user);
$auth->logout();

$auth->guard('jwt')->user();       // explicit guard
$token = $auth->createToken($user, ['role' => 'admin']);  // JWT only
```

### `session` guard

Stores the user id in the session. `attempt()` always runs a real
Argon2id `password_verify()` — even when no matching row is found, against
a fixed dummy hash — so response time never leaks whether an email/username
exists (a classic account-enumeration side channel).

### `jwt` guard

Stateless — reads the `Authorization: Bearer <token>` header. Requires
`JWT_SECRET` to be at least 32 bytes (`firebase/php-jwt` requires a real
256-bit key for HS256); a short/missing secret throws `RuntimeException`
rather than silently failing every check.

```php
$router->post('/login', function (Request $request, AuthManager $auth) {
    // verify credentials yourself, then:
    return json(['token' => $auth->createToken($user)]);
});
```

### Guard-aware middleware

```php
$router->get('/dashboard', ...)->middleware('auth');          // default guard
$router->get('/api/me', ...)->middleware('auth:jwt');         // explicit guard
$router->get('/login', ...)->middleware('guest');              // RedirectIfAuthenticated
```

See [Middleware](middleware.md).

## Password hashing

```php
use Marrow\Auth\Hash;

Hash::make($password);              // Argon2id
Hash::verify($password, $hash);
Hash::needsRehash($hash);           // true if cost params changed since hashing
```

`bcrypt($password)` (global helper) is a thin alias for `Hash::make()`
despite the name — it's Argon2id under the hood, not bcrypt.

## Authorization — `Gate` and Policies

```php
$gate = app(\Marrow\Auth\Gate::class);

$gate->define('moderate', fn (object $user) => $user->hasRole('moderator'));
$gate->allows('moderate');                        // bool
$gate->authorize('moderate');                     // throws 403 on denial

$gate->policy(Post::class, PostPolicy::class);
$gate->allows('update', $post);                   // → PostPolicy::update($user, $post)
```

```php
class PostPolicy extends \Marrow\Auth\Policy
{
    public function before(object $user, string $ability): ?bool
    {
        return null; // return true/false to short-circuit every ability
    }

    public function update(object $user, Post $post): bool
    {
        return $user->id === $post->user_id;
    }
}
```

```bash
php forge make:policy PostPolicy --model=App\\Models\\Post
```

### Policy resolution

Explicit (`Gate::policy()`) takes priority; otherwise a **sibling-namespace
convention** applies: `Modules\Blog\Models\Post` → `Modules\Blog\Policies\PostPolicy`.

### `before`/`after` hooks

```php
$gate->before(function (object $user, string $ability) {
    return $user->hasRole('admin') ? true : null;   // super-admin bypass
});

$gate->after(function (object $user, string $ability, array $args, bool $result) {
    // audit every authorization decision, optionally override $result
    return null;
});
```

The skeleton wires exactly this pattern for you via `config/rbac.php`'s
`super_admin_role` — see below.

### Explicit-policy authorization (the "bouncer" style)

```php
$gate->with(PostPolicy::class)->authorize('edit', $post);

// From a Controller:
$this->bouncer(PostPolicy::class)->allows('edit', $post);
$this->authorizePolicy(PostPolicy::class, 'edit', $post);
```

Unlike `Gate::allows()`, this targets a policy explicitly rather than
resolving it from the model's class — useful when the convention doesn't
apply or when a model has more than one relevant policy.

### From a Controller

```php
$this->authorize('update', $post);      // throws 403
$this->can('update', $post);            // bool
$this->cannot('update', $post);
```

### In Twig

```twig
{% if can('update', post) %}
    <a href="{{ route('posts.edit', {id: post.id}) }}">Edit</a>
{% endif %}
```

## RBAC — roles & permissions

Backed by two plain SQL-driven classes (not `Model`/ORM):
`Auth\RBAC\Role` (table `roles`) and `Auth\RBAC\Permission` (table
`permissions`), joined by `role_permissions`, plus `user_roles` and the
optional `user_permissions` for direct user-level grants. All are created
by the skeleton's migrations.

```php
$admin = Role::findOrCreate('Administrator', 'admin');
$admin->givePermissionTo('posts.manage');
$admin->hasPermission('posts.manage');   // true
$admin->revokePermissionTo('posts.manage');
$admin->syncPermissions(['posts.view', 'posts.manage']);

Permission::findOrCreate('Manage posts', 'posts.manage', group: 'posts');
Permission::grouped();   // ['posts' => [...], 'users' => [...]]
```

Permission slug convention: `"resource.action"` (`posts.create`, `users.delete`).

### Traits — apply to your User model

```php
use Marrow\Auth\Concerns\{HasRole, HasPermission, HasTwoFactor, Auditable};

class User extends Model
{
    use HasRole, HasPermission, HasTwoFactor, Auditable {
        HasRole::getPrimaryKeyValue insteadof HasPermission;
    }
}
```

`HasRole` and `HasPermission` both declare a private `getPrimaryKeyValue()`
helper with identical bodies — using both traits together is a genuine PHP
trait collision (private visibility doesn't exempt it) and **must** be
resolved with the `insteadof` block above, exactly as shown.

```php
$user->assignRole('editor');
$user->hasRole('editor');
$user->hasAnyRole(['editor', 'admin']);
$user->hasAllRoles(['editor', 'verified']);
$user->removeRole('editor');
$user->syncRoles(['viewer']);
$user->roles();                     // Role[]

$user->hasPermission('posts.create');
$user->hasAnyPermission([...]);
$user->hasAllPermissions([...]);
$user->can('update', $post);        // delegates to Gate
$user->cannot('update', $post);
$user->givePermissionTo('posts.create');   // direct, user-level grant
$user->revokePermissionTo('posts.create');
```

Required tables: `roles`, `permissions`, `role_permissions`, `user_roles`,
and (only if you call `givePermissionTo()`/`revokePermissionTo()` directly)
`user_permissions`.

### Super-admin bypass

```php
// config/rbac.php
return ['super_admin_role' => env('RBAC_SUPER_ADMIN_ROLE', 'admin')];
```

Any user with this role slug bypasses every `Gate` check, via a `before`
hook registered automatically in `Application::bindCoreServices()`. Set it
to a falsy value to disable the bypass and require every ability to be
explicitly granted.

## Two-factor authentication

`HasTwoFactor` implements TOTP (RFC 6238) with no external dependency.
Requires three columns: `two_factor_secret`, `two_factor_recovery_codes`,
`two_factor_enabled_at` (all nullable — see the skeleton's migration).

```php
$secret = $user->enableTwoFactor();          // base32 secret, show as a QR code
$user->save();
$user->twoFactorQrUri('MyApp', $user->email); // otpauth:// URI
$user->verifyTwoFactor($code);                // 6-digit OTP, ±30s window either side
$user->recoveryCodes();                       // 8 single-use codes, burned on use
$user->generateRecoveryCodes();               // regenerate
$user->twoFactorEnabled();
$user->disableTwoFactor();
```

`two_factor_secret` is stored encrypted (`Support\Crypto`, AES-256-GCM) —
never plaintext — and requires `APP_KEY` to be set.

## Audit logging

`Auditable` auto-logs to `audit_logs` (`user_id`, `event`, `model_type`,
`model_id`, `old_values`, `new_values`, `ip_address`, `user_agent`,
`created_at`):

```php
class Post extends Model
{
    use Auditable;

    protected array $auditExclude = ['internal_notes']; // merged with the default password/token exclusions
}
```

`auditCreated()`/`auditUpdated($original)`/`auditDeleted()` are **not**
wired to `save()`/`delete()` automatically. `Model::fireEvent()` only
dispatches an event if a class named `Marrow\Events\Model\{Created,
Updated, Deleted, ...}` exists — the framework ships none of them — so the
simplest way to auto-audit a model is to override `save()`/`delete()`
directly:

```php
class Post extends Model
{
    use Auditable;

    public function save(): bool
    {
        $wasNew = !$this->exists();
        $original = $this->getOriginal();
        $ok = parent::save();
        if ($ok) {
            $wasNew ? $this->auditCreated() : $this->auditUpdated($original);
        }
        return $ok;
    }
}
```

Or, if you'd rather use real model events, define the
`Marrow\Events\Model\{Created,Updated,Deleted}` classes yourself (see
[Database & ORM](database.md#model-events)) and call the matching
`audit*()` method from a listener.

Every write is wrapped in a `try/catch` internally — an audit failure never
breaks the triggering request. Query a model's history with
`$model->auditLogs()`.
