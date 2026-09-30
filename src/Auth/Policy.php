<?php

declare(strict_types=1);

namespace Marrow\Auth;

/**
 * Base class for authorization policy objects.
 *
 * Extend this class and implement one method per ability:
 *
 *   class PostPolicy extends Policy
 *   {
 *       public function view(object $user, Post $post): bool
 *       {
 *           return true; // anyone logged in can view
 *       }
 *
 *       public function update(object $user, Post $post): bool
 *       {
 *           return $user->id === $post->user_id;
 *       }
 *   }
 *
 * The before() method runs before every ability check.
 * Return a bool to short-circuit; return null to continue normally.
 */
abstract class Policy
{
    /**
     * Pre-check called before every ability method.
     *
     * Returning true grants the ability unconditionally.
     * Returning false denies the ability unconditionally.
     * Returning null defers to the ability-specific method.
     */
    public function before(object $user, string $ability): ?bool
    {
        return null;
    }
}
