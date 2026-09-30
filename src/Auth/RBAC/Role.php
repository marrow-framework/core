<?php

declare(strict_types=1);

namespace Marrow\Auth\RBAC;

use Marrow\Application;
use Marrow\Database\Connection;
use Marrow\Auth\RBAC\Permission;

/**
 * RBAC Role model.
 *
 * Table: roles (id, name, slug, description, created_at, updated_at)
 *
 * Usage:
 *   $role = Role::findBySlug('admin');
 *   $role->givePermissionTo('posts.create');
 *   $role->hasPermission('posts.create');  // true
 */
class Role
{
    public readonly int    $id;
    public readonly string $name;
    public readonly string $slug;
    public readonly string $description;
    public readonly string $created_at;
    public readonly string $updated_at;

    private array|null $cachedPermissions = null;

    /** @internal used by Permission and other finders */
    public static function fromRow(array $row): static
    {
        return new static($row);
    }

    private function __construct(array $row)
    {
        $this->id          = (int)    ($row['id']          ?? 0);
        $this->name        = (string) ($row['name']        ?? '');
        $this->slug        = (string) ($row['slug']        ?? '');
        $this->description = (string) ($row['description'] ?? '');
        $this->created_at  = (string) ($row['created_at']  ?? '');
        $this->updated_at  = (string) ($row['updated_at']  ?? '');
    }

    // ── Factory / finders ─────────────────────────────────────────────

    public static function find(int $id): ?static
    {
        $row = self::db()->selectOne('SELECT * FROM roles WHERE id = ?', [$id]);
        return $row ? new static($row) : null;
    }

    public static function findBySlug(string $slug): ?static
    {
        $row = self::db()->selectOne('SELECT * FROM roles WHERE slug = ?', [$slug]);
        return $row ? new static($row) : null;
    }

    public static function findOrCreate(string $name, string $slug, string $description = ''): static
    {
        $existing = self::findBySlug($slug);
        if ($existing !== null) {
            return $existing;
        }
        return self::create($name, $slug, $description);
    }

    public static function create(string $name, string $slug, string $description = ''): static
    {
        $db  = self::db();
        $now = date('Y-m-d H:i:s');
        $db->statement(
            'INSERT INTO roles (name, slug, description, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            [$name, $slug, $description, $now, $now]
        );
        $id = (int) $db->lastInsertId();
        return new static([
            'id' => $id, 'name' => $name, 'slug' => $slug,
            'description' => $description, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** @return static[] */
    public static function all(): array
    {
        return array_map(
            fn(array $row) => new static($row),
            self::db()->select('SELECT * FROM roles ORDER BY name')
        );
    }

    // ── Permissions ───────────────────────────────────────────────────

    /** @return Permission[] */
    public function permissions(): array
    {
        if ($this->cachedPermissions !== null) {
            return $this->cachedPermissions;
        }

        $rows = self::db()->select(
            'SELECT p.* FROM permissions p
             INNER JOIN role_permissions rp ON rp.permission_id = p.id
             WHERE rp.role_id = ?
             ORDER BY p.slug',
            [$this->id]
        );

        $this->cachedPermissions = array_map(
            fn(array $r) => Permission::fromRow($r),
            $rows
        );

        return $this->cachedPermissions;
    }

    public function hasPermission(string $slug): bool
    {
        foreach ($this->permissions() as $perm) {
            if ($perm->slug === $slug) {
                return true;
            }
        }
        return false;
    }

    public function givePermissionTo(string|Permission $permission): void
    {
        $perm = is_string($permission)
            ? Permission::findBySlug($permission) ?? Permission::create($permission, $permission)
            : $permission;

        $exists = self::db()->selectOne(
            'SELECT 1 FROM role_permissions WHERE role_id = ? AND permission_id = ?',
            [$this->id, $perm->id]
        );

        if (!$exists) {
            self::db()->statement(
                'INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)',
                [$this->id, $perm->id]
            );
        }

        $this->cachedPermissions = null;
    }

    public function revokePermissionTo(string|Permission $permission): void
    {
        $slug = is_string($permission) ? $permission : $permission->slug;
        $perm = Permission::findBySlug($slug);

        if ($perm !== null) {
            self::db()->statement(
                'DELETE FROM role_permissions WHERE role_id = ? AND permission_id = ?',
                [$this->id, $perm->id]
            );
            $this->cachedPermissions = null;
        }
    }

    public function syncPermissions(array $permissions): void
    {
        self::db()->statement('DELETE FROM role_permissions WHERE role_id = ?', [$this->id]);
        $this->cachedPermissions = null;

        foreach ($permissions as $perm) {
            $this->givePermissionTo($perm);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private static function db(): Connection
    {
        return Application::getInstance()->getContainer()->make(Connection::class);
    }
}
