<?php

declare(strict_types=1);

namespace Marrow\Auth\RBAC;

use Marrow\Application;
use Marrow\Database\Connection;
use Marrow\Auth\RBAC\Role;

/**
 * RBAC Permission model.
 *
 * Table: permissions (id, name, slug, group, description, created_at, updated_at)
 *
 * Slug convention: "resource.action" e.g. "posts.create", "users.delete".
 *
 * Usage:
 *   Permission::findBySlug('posts.create')
 *   Permission::create('Create Posts', 'posts.create', 'posts')
 */
class Permission
{
    public readonly int    $id;
    public readonly string $name;
    public readonly string $slug;
    public readonly string $group;
    public readonly string $description;
    public readonly string $created_at;
    public readonly string $updated_at;

    private function __construct(array $row)
    {
        $this->id          = (int)    ($row['id']          ?? 0);
        $this->name        = (string) ($row['name']        ?? '');
        $this->slug        = (string) ($row['slug']        ?? '');
        $this->group       = (string) ($row['group']       ?? '');
        $this->description = (string) ($row['description'] ?? '');
        $this->created_at  = (string) ($row['created_at']  ?? '');
        $this->updated_at  = (string) ($row['updated_at']  ?? '');
    }

    /** @internal used by Role */
    public static function fromRow(array $row): static
    {
        return new static($row);
    }

    // ── Factory / finders ─────────────────────────────────────────────

    public static function find(int $id): ?static
    {
        $row = self::db()->selectOne('SELECT * FROM permissions WHERE id = ?', [$id]);
        return $row ? new static($row) : null;
    }

    public static function findBySlug(string $slug): ?static
    {
        $row = self::db()->selectOne('SELECT * FROM permissions WHERE slug = ?', [$slug]);
        return $row ? new static($row) : null;
    }

    public static function create(
        string $name,
        string $slug,
        string $group = '',
        string $description = ''
    ): static {
        $db  = self::db();
        $now = date('Y-m-d H:i:s');
        $db->statement(
            'INSERT INTO permissions (name, slug, `group`, description, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$name, $slug, $group, $description, $now, $now]
        );
        $id = (int) $db->lastInsertId();
        return new static([
            'id' => $id, 'name' => $name, 'slug' => $slug,
            'group' => $group, 'description' => $description,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public static function findOrCreate(
        string $name,
        string $slug,
        string $group = '',
        string $description = ''
    ): static {
        return self::findBySlug($slug) ?? self::create($name, $slug, $group, $description);
    }

    /** @return static[] */
    public static function all(): array
    {
        return array_map(
            fn(array $row) => new static($row),
            self::db()->select('SELECT * FROM permissions ORDER BY `group`, slug')
        );
    }

    /** @return array<string, list<static>> */
    public static function grouped(): array
    {
        $grouped = [];
        foreach (self::all() as $perm) {
            $grouped[$perm->group][] = $perm;
        }
        return $grouped;
    }

    // ── Roles ─────────────────────────────────────────────────────────

    /** @return Role[] */
    public function roles(): array
    {
        $rows = self::db()->select(
            'SELECT r.* FROM roles r
             INNER JOIN role_permissions rp ON rp.role_id = r.id
             WHERE rp.permission_id = ?
             ORDER BY r.name',
            [$this->id]
        );

        return array_map(fn(array $r) => Role::fromRow($r), $rows);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private static function db(): Connection
    {
        return Application::getInstance()->getContainer()->make(Connection::class);
    }
}
