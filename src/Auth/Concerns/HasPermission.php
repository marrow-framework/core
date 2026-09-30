<?php

declare(strict_types=1);

namespace Marrow\Auth\Concerns;

use Marrow\Application;
use Marrow\Auth\Gate;
use Marrow\Auth\RBAC\Permission;
use Marrow\Database\Connection;

/**
 * HasPermission — permission checking via roles and the Gate.
 *
 * Requires the HasRole trait (or a compatible roles() method) to be present
 * on the same class.
 *
 * Usage:
 *   $user->hasPermission('posts.create')
 *   $user->can('update', $post)     // delegates to Gate
 *   $user->cannot('delete', $post)
 */
trait HasPermission
{
    /** @var string[]|null Flattened slugs cache. */
    private ?array $_cachedPermSlugs = null;

    // ── Checks ────────────────────────────────────────────────────────

    public function hasPermission(string $slug): bool
    {
        return \in_array($slug, $this->allPermissionSlugs(), true);
    }

    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $p) {
            if ($this->hasPermission($p)) {
                return true;
            }
        }
        return false;
    }

    public function hasAllPermissions(array $permissions): bool
    {
        foreach ($permissions as $p) {
            if (!$this->hasPermission($p)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Delegate ability check to the Gate (covers policies + closures).
     * No try/catch: a Gate resolution failure is a real misconfiguration
     * and must surface as one, not be indistinguishable from a legitimate
     * permission denial (see FrameworkExtension::funcCan() for the same fix).
     */
    public function can(string $ability, mixed $arguments = []): bool
    {
        $gate = Application::getInstance()->getContainer()->make(Gate::class);
        return $gate->forUser($this)->allows($ability, $arguments);
    }

    public function cannot(string $ability, mixed $arguments = []): bool
    {
        return !$this->can($ability, $arguments);
    }

    // ── Direct permission assignment (user-level) ─────────────────────

    public function givePermissionTo(string $slug): void
    {
        $perm = Permission::findBySlug($slug)
            ?? Permission::create($slug, $slug);

        $db = $this->permDb();
        $exists = $db->selectOne(
            'SELECT 1 FROM user_permissions WHERE user_id = ? AND permission_id = ?',
            [$this->getPrimaryKeyValue(), $perm->id]
        );

        if (!$exists) {
            $db->statement(
                'INSERT INTO user_permissions (user_id, permission_id) VALUES (?, ?)',
                [$this->getPrimaryKeyValue(), $perm->id]
            );
        }

        $this->_cachedPermSlugs = null;
    }

    public function revokePermissionTo(string $slug): void
    {
        $perm = Permission::findBySlug($slug);
        if ($perm === null) {
            return;
        }

        $this->permDb()->statement(
            'DELETE FROM user_permissions WHERE user_id = ? AND permission_id = ?',
            [$this->getPrimaryKeyValue(), $perm->id]
        );

        $this->_cachedPermSlugs = null;
    }

    // ── Internal ──────────────────────────────────────────────────────

    /** Collect all permission slugs from roles + direct assignments. */
    private function allPermissionSlugs(): array
    {
        if ($this->_cachedPermSlugs !== null) {
            return $this->_cachedPermSlugs;
        }

        $slugs = [];

        // Via roles (from HasRole trait)
        if (method_exists($this, 'roles')) {
            foreach ($this->roles() as $role) {
                foreach ($role->permissions() as $perm) {
                    $slugs[] = $perm->slug;
                }
            }
        }

        // Direct user-level permissions (if user_permissions table exists)
        try {
            $rows = $this->permDb()->select(
                'SELECT p.slug FROM permissions p
                 INNER JOIN user_permissions up ON up.permission_id = p.id
                 WHERE up.user_id = ?',
                [$this->getPrimaryKeyValue()]
            );
            foreach ($rows as $row) {
                $slugs[] = $row['slug'];
            }
        } catch (\Throwable) {
            // user_permissions table may not exist — role-based only
        }

        $this->_cachedPermSlugs = array_unique($slugs);
        return $this->_cachedPermSlugs;
    }

    private function getPrimaryKeyValue(): int|string
    {
        $pk = property_exists($this, 'primaryKey') ? $this->primaryKey : 'id';
        return $this->{$pk};
    }

    private function permDb(): Connection
    {
        return Application::getInstance()->getContainer()->make(Connection::class);
    }
}
