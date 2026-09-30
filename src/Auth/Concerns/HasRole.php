<?php

declare(strict_types=1);

namespace Marrow\Auth\Concerns;

use Marrow\Application;
use Marrow\Auth\RBAC\Role;
use Marrow\Database\Connection;

/**
 * HasRole — attach to a User model to enable role-based access.
 *
 * Requires:
 *   - user_roles table: (user_id, role_id)
 *   - Model must expose $this->id (or override getPrimaryKeyValue())
 *
 * Usage on User model:
 *   use HasRole;
 *
 * Then:
 *   $user->assignRole('admin')
 *   $user->hasRole('editor')
 *   $user->roles()
 */
trait HasRole
{
    /** @var Role[]|null Cached roles for this user. */
    private ?array $_cachedRoles = null;

    // ── Getters ───────────────────────────────────────────────────────

    /** @return Role[] */
    public function roles(): array
    {
        if ($this->_cachedRoles !== null) {
            return $this->_cachedRoles;
        }

        $rows = $this->rbacDb()->select(
            'SELECT r.* FROM roles r
             INNER JOIN user_roles ur ON ur.role_id = r.id
             WHERE ur.user_id = ?
             ORDER BY r.name',
            [$this->getPrimaryKeyValue()]
        );

        $this->_cachedRoles = array_map(
            fn(array $row) => Role::fromRow($row),
            $rows
        );

        return $this->_cachedRoles;
    }

    public function hasRole(string|array $role): bool
    {
        $check = is_array($role) ? $role : [$role];
        foreach ($this->roles() as $r) {
            if (\in_array($r->slug, $check, true) || \in_array($r->name, $check, true)) {
                return true;
            }
        }
        return false;
    }

    public function hasAnyRole(array $roles): bool
    {
        return $this->hasRole($roles);
    }

    public function hasAllRoles(array $roles): bool
    {
        foreach ($roles as $role) {
            if (!$this->hasRole($role)) {
                return false;
            }
        }
        return true;
    }

    // ── Mutations ─────────────────────────────────────────────────────

    public function assignRole(string|Role $role): void
    {
        $resolved = $this->resolveRole($role);
        if ($resolved === null) {
            return;
        }

        $exists = $this->rbacDb()->selectOne(
            'SELECT 1 FROM user_roles WHERE user_id = ? AND role_id = ?',
            [$this->getPrimaryKeyValue(), $resolved->id]
        );

        if (!$exists) {
            $this->rbacDb()->statement(
                'INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)',
                [$this->getPrimaryKeyValue(), $resolved->id]
            );
        }

        $this->_cachedRoles = null;
    }

    public function removeRole(string|Role $role): void
    {
        $resolved = $this->resolveRole($role);
        if ($resolved === null) {
            return;
        }

        $this->rbacDb()->statement(
            'DELETE FROM user_roles WHERE user_id = ? AND role_id = ?',
            [$this->getPrimaryKeyValue(), $resolved->id]
        );

        $this->_cachedRoles = null;
    }

    /** Replace all roles with the given set. */
    public function syncRoles(array $roles): void
    {
        $this->rbacDb()->statement(
            'DELETE FROM user_roles WHERE user_id = ?',
            [$this->getPrimaryKeyValue()]
        );
        $this->_cachedRoles = null;

        foreach ($roles as $role) {
            $this->assignRole($role);
        }
    }

    // ── Internal ──────────────────────────────────────────────────────

    private function resolveRole(string|Role $role): ?Role
    {
        if ($role instanceof Role) {
            return $role;
        }
        return Role::findBySlug($role) ?? Role::findBySlug(strtolower(str_replace(' ', '-', $role)));
    }

    private function getPrimaryKeyValue(): int|string
    {
        $pk = property_exists($this, 'primaryKey') ? $this->primaryKey : 'id';
        return $this->{$pk};
    }

    private function rbacDb(): Connection
    {
        return Application::getInstance()->getContainer()->make(Connection::class);
    }
}
