<?php

declare(strict_types=1);

namespace Marrow\Auth;

use Marrow\Exceptions\HttpException;

/**
 * A Gate scoped to one explicit policy class — the AdonisJS-v6 "bouncer.with()"
 * style of authorization.
 *
 *   $bouncer = $gate->with(PostPolicy::class);
 *   $bouncer->allows('edit', $post);      // bool
 *   $bouncer->denies('edit', $post);      // bool
 *   $bouncer->authorize('edit', $post);   // throws 403 on denial
 *   $bouncer->forUser($other)->allows('edit', $post);
 *
 * Abilities map to methods on the policy: `allows('edit', $post)` calls
 * `PostPolicy::edit($user, $post)`. The policy's before() hook and the Gate's
 * global before/after hooks (e.g. the super-admin bypass) still apply, so
 * behaviour is consistent with convention-based checks.
 */
class PolicyGate
{
    public function __construct(
        private readonly Gate $gate,
        private readonly string $policyClass,
        private readonly mixed $userOverride = false
    ) {
    }

    /** Scope the checks to a specific user. */
    public function forUser(?object $user): static
    {
        return new static($this->gate, $this->policyClass, $user);
    }

    public function allows(string $ability, mixed ...$arguments): bool
    {
        return $this->gate->checkPolicy(
            $this->policyClass,
            $ability,
            array_values($arguments),
            $this->userOverride
        );
    }

    public function denies(string $ability, mixed ...$arguments): bool
    {
        return !$this->allows($ability, ...$arguments);
    }

    /**
     * Assert the current (or scoped) user passes the ability.
     *
     * @throws HttpException 403 on denial
     */
    public function authorize(string $ability, mixed ...$arguments): void
    {
        if ($this->denies($ability, ...$arguments)) {
            throw new HttpException(403, "Cette action n'est pas autorisée.");
        }
    }
}
