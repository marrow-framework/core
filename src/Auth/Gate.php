<?php

declare(strict_types=1);

namespace Marrow\Auth;

use Marrow\Container;
use Marrow\Exceptions\HttpException;

/**
 * Authorization Gate — checks whether a user may perform an action.
 *
 * Ability closures:
 *   Gate::define('admin', fn($user) => $user->role === 'admin')
 *   Gate::allows('admin')           // bool
 *   Gate::authorize('admin')        // throws 403 on denial
 *
 * Policy-based:
 *   Gate::policy(Post::class, PostPolicy::class)
 *   Gate::allows('update', $post)
 *   Gate::allows('update', [Post::class, $id])  // lazy-load pattern
 *
 * Convention: ModelPolicy found at sibling Policies\{Model}Policy namespace.
 */
class Gate
{
    /** @var array<string, callable> */
    private array $abilities = [];

    /** @var array<string, string> model FQCN → policy FQCN */
    private array $policies = [];

    /** @var callable[] */
    private array $beforeCallbacks = [];

    /** @var callable[] */
    private array $afterCallbacks = [];

    /** Override the resolved user (used by forUser()). */
    private mixed $userOverride = false;

    public function __construct(
        private readonly AuthManager $auth,
        private readonly Container   $container
    ) {}

    // ── Registration ──────────────────────────────────────────────────

    public function define(string $ability, callable $callback): static
    {
        $this->abilities[$ability] = $callback;
        return $this;
    }

    public function policy(string $model, string $policyClass): static
    {
        $this->policies[$model] = $policyClass;
        return $this;
    }

    /**
     * Called before any ability check.
     * Return a bool to short-circuit; return null to continue normally.
     */
    public function before(callable $callback): static
    {
        $this->beforeCallbacks[] = $callback;
        return $this;
    }

    /**
     * Called after every ability check with the final boolean result.
     * Return a bool to override; return null to keep current result.
     */
    public function after(callable $callback): static
    {
        $this->afterCallbacks[] = $callback;
        return $this;
    }

    // ── Checks ────────────────────────────────────────────────────────

    public function allows(string $ability, mixed $arguments = []): bool
    {
        return $this->raw($ability, $this->normalize($arguments));
    }

    public function denies(string $ability, mixed $arguments = []): bool
    {
        return !$this->allows($ability, $arguments);
    }

    /** True if the user passes ANY of the given abilities. */
    public function any(array $abilities, mixed $arguments = []): bool
    {
        foreach ($abilities as $ability) {
            if ($this->allows($ability, $arguments)) {
                return true;
            }
        }
        return false;
    }

    /** True if the user passes NONE of the given abilities. */
    public function none(array $abilities, mixed $arguments = []): bool
    {
        return !$this->any($abilities, $arguments);
    }

    /**
     * Assert that the current user has the given ability.
     *
     * @throws HttpException 403 on denial
     */
    public function authorize(string $ability, mixed $arguments = []): void
    {
        if ($this->denies($ability, $arguments)) {
            throw new HttpException(403, "Cette action n'est pas autorisée.");
        }
    }

    // ── User scoping ──────────────────────────────────────────────────

    /** Return a Gate instance scoped to a specific user. */
    public function forUser(?object $user): static
    {
        $clone               = clone $this;
        $clone->userOverride = $user;
        return $clone;
    }

    // ── Explicit policy targeting (AdonisJS-style bouncer) ─────────────

    /**
     * Scope authorization to an explicit policy class.
     *
     *   $gate->with(PostPolicy::class)->authorize('edit', $post);
     */
    public function with(string $policyClass): PolicyGate
    {
        return new PolicyGate($this, $policyClass, $this->userOverride);
    }

    /**
     * Run an ability against an explicit policy class for the current
     * (or supplied) user. Honours the policy before() hook and the Gate's
     * global before/after hooks. Returns false when no user is resolved.
     *
     * @internal Used by {@see PolicyGate}; prefer with() in application code.
     */
    public function checkPolicy(
        string $policyClass,
        string $ability,
        array $arguments = [],
        mixed $userOverride = false
    ): bool {
        $user = $userOverride !== false ? $userOverride : $this->resolveUser();

        if ($user === null) {
            return false;
        }

        $before = $this->runBeforeCallbacks($user, $ability, $arguments);
        if ($before !== null) {
            return $before;
        }

        $policy = $this->container->make($policyClass);
        $result = $this->callPolicyInstance($policy, $user, $ability, $arguments);

        return $this->runAfterCallbacks($user, $ability, $arguments, $result);
    }

    // ── Policy resolution ─────────────────────────────────────────────

    /** Resolve and return the policy object for a model class or instance. */
    public function getPolicyFor(string|object $model): ?object
    {
        $class = is_object($model) ? $model::class : $model;

        // Explicit registration
        if (isset($this->policies[$class])) {
            return $this->container->make($this->policies[$class]);
        }

        // Convention-based discovery
        $guessed = $this->guessPolicy($class);
        if ($guessed !== null && class_exists($guessed)) {
            return $this->container->make($guessed);
        }

        return null;
    }

    // ── Internal ──────────────────────────────────────────────────────

    private function raw(string $ability, array $arguments): bool
    {
        $user = $this->resolveUser();

        if ($user === null) {
            return false;
        }

        // Before-hooks
        $before = $this->runBeforeCallbacks($user, $ability, $arguments);
        if ($before !== null) {
            return $before;
        }

        // Ability closure or policy method
        if (isset($this->abilities[$ability])) {
            $result = (bool) ($this->abilities[$ability])($user, ...$arguments);
        } else {
            $result = $this->callPolicy($user, $ability, $arguments);
        }

        // After-hooks
        return $this->runAfterCallbacks($user, $ability, $arguments, $result);
    }

    private function runBeforeCallbacks(object $user, string $ability, array $arguments): ?bool
    {
        foreach ($this->beforeCallbacks as $cb) {
            $result = $cb($user, $ability, $arguments);
            if ($result !== null) {
                return (bool) $result;
            }
        }
        return null;
    }

    private function runAfterCallbacks(object $user, string $ability, array $arguments, bool $result): bool
    {
        foreach ($this->afterCallbacks as $cb) {
            $override = $cb($user, $ability, $arguments, $result);
            if ($override !== null) {
                $result = (bool) $override;
            }
        }
        return $result;
    }

    private function callPolicy(object $user, string $ability, array $arguments): bool
    {
        $subject = $arguments[0] ?? null;
        if ($subject === null) {
            return false;
        }

        $policy = $this->getPolicyFor($subject);
        if ($policy === null) {
            return false;
        }

        return $this->callPolicyInstance($policy, $user, $ability, $arguments);
    }

    /**
     * Invoke an ability method on a resolved policy instance, honouring the
     * policy's own before() hook. Shared by convention-based and explicit
     * (with()) policy checks.
     */
    private function callPolicyInstance(object $policy, object $user, string $ability, array $arguments): bool
    {
        if (method_exists($policy, 'before')) {
            $before = $policy->before($user, $ability);
            if ($before !== null) {
                return (bool) $before;
            }
        }

        if (!method_exists($policy, $ability)) {
            return false;
        }

        return (bool) $policy->{$ability}($user, ...$arguments);
    }

    /**
     * Guess the policy class for a model using sibling-namespace convention:
     *   Modules\Blog\Models\Post → Modules\Blog\Policies\PostPolicy
     *   Marrow\Auth\RBAC\Role  → Marrow\Auth\Policies\RolePolicy
     */
    private function guessPolicy(string $modelClass): ?string
    {
        $basename = basename(str_replace('\\', '/', $modelClass));
        $ns       = substr($modelClass, 0, (int) strrpos($modelClass, '\\'));
        $parent   = substr($ns, 0, (int) strrpos($ns, '\\'));

        if ($parent === '') {
            return null;
        }

        return $parent . '\\Policies\\' . $basename . 'Policy';
    }

    private function resolveUser(): ?object
    {
        if ($this->userOverride !== false) {
            return $this->userOverride;
        }

        try {
            return $this->auth->user();
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalize(mixed $arguments): array
    {
        return is_array($arguments) ? array_values($arguments) : [$arguments];
    }
}
