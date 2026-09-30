<?php

declare(strict_types=1);

namespace Marrow\Database\Migrations;

use Marrow\Database\Connection;
use Marrow\Database\Schema\Schema;

/**
 * Discovers, runs, and rolls back migrations.
 * Tracks ran migrations in a `migrations` table with batch numbers.
 */
class Migrator
{
    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
        $this->ensureTable();
    }

    /** @throws \RuntimeException If a discovered migration file doesn't return a Migration instance, or its class can't be found. */
    public function run(string $path): array
    {
        $pending = $this->getPendingMigrations($path);
        if (empty($pending)) {
            return [];
        }

        $batch = $this->getLastBatch() + 1;
        $ran = [];

        foreach ($pending as $file) {
            $migration = $this->resolveMigration($file);
            $t0 = hrtime(true);

            // up() and the tracking insert succeed or fail together — a
            // migration that partially applies before throwing must not be
            // marked as ran, or it would silently be skipped on retry while
            // leaving the schema half-changed. (MySQL DDL still auto-commits
            // regardless — this only buys atomicity where the driver supports
            // transactional DDL, e.g. SQLite/PostgreSQL, but is harmless elsewhere.)
            $this->db->transaction(function () use ($migration, $file, $batch): void {
                $migration->up();
                $this->db->insert('migrations', [
                    'migration' => basename($file, '.php'),
                    'batch' => $batch,
                    'ran_at' => date('Y-m-d H:i:s'),
                ]);
            });

            $ms = (int) round((hrtime(true) - $t0) / 1_000_000);
            $ran[] = ['file' => basename($file), 'ms' => $ms];
        }

        return $ran;
    }

    /** @throws \RuntimeException If a migration file doesn't return a Migration instance, or its class can't be found. */
    public function rollback(string $path): array
    {
        $lastBatch = $this->getLastBatch();
        if ($lastBatch === 0) {
            return [];
        }

        $rows = $this->db->select(
            'SELECT migration FROM migrations WHERE batch = ? ORDER BY id DESC',
            [$lastBatch]
        );

        $rolledBack = [];

        foreach ($rows as $row) {
            $file = $path . '/' . $row['migration'] . '.php';
            if (!is_file($file)) {
                continue;
            }

            $migration = $this->resolveMigration($file);
            $t0 = hrtime(true);

            $this->db->transaction(function () use ($migration, $row): void {
                $migration->down();
                $this->db->statement('DELETE FROM migrations WHERE migration = ?', [$row['migration']]);
            });

            $ms = (int) round((hrtime(true) - $t0) / 1_000_000);
            $rolledBack[] = ['file' => basename($file), 'ms' => $ms];
        }

        return $rolledBack;
    }

    public function status(string $path): array
    {
        $ran = array_column(
            $this->db->select('SELECT migration, batch FROM migrations ORDER BY id'),
            null,
            'migration'
        );

        $files = $this->getMigrationFiles($path);
        $status = [];

        foreach ($files as $file) {
            $name = basename($file, '.php');
            $status[] = [
                'migration' => $name,
                'ran' => isset($ran[$name]),
                'batch' => $ran[$name]['batch'] ?? null,
            ];
        }

        return $status;
    }

    /**
     * Drops every table (used by `migrate --fresh`/`migrate:fresh`).
     *
     * `listTableNames()` has no notion of foreign-key dependency order, so
     * reversing it is not a reliable drop order — on MySQL/PostgreSQL,
     * where Schema::buildTable() emits real FOREIGN KEY constraints (unlike
     * SQLite, which ignores them), dropping a referenced table before its
     * referencing table fails outright. FK enforcement is disabled for the
     * duration of this call on both dialects instead of trying to compute a
     * safe order.
     */
    public function dropAll(): void
    {
        $sm       = $this->db->getSchemaManager();
        $tables   = $sm->listTableNames();
        $platform = strtolower(class_basename(get_class($this->db->getPlatform())));
        $isMysql  = str_contains($platform, 'mysql') || str_contains($platform, 'maria');
        $isPgsql  = str_contains($platform, 'postgre') || str_contains($platform, 'pgsql');

        if ($isMysql) {
            $this->db->statement('SET FOREIGN_KEY_CHECKS = 0');
        }

        try {
            foreach (array_reverse($tables) as $table) {
                if ($table === 'migrations') {
                    continue;
                }
                if ($isPgsql) {
                    // No MySQL-style session-wide FK-disable exists for
                    // Postgres; CASCADE drops dependent constraints instead.
                    $this->db->statement('DROP TABLE IF EXISTS "' . $table . '" CASCADE');
                } else {
                    Schema::drop($table);
                }
            }
        } finally {
            if ($isMysql) {
                $this->db->statement('SET FOREIGN_KEY_CHECKS = 1');
            }
        }

        $this->db->statement('DELETE FROM migrations');
    }

    public function fresh(string $path): array
    {
        $this->dropAll();
        return $this->run($path);
    }

    /**
     * Discover all migration directories under a base path, plus any
     * explicitly given module classes'.
     *
     * The glob-based scan covers the conventional local layout:
     * {base}/database/migrations, {base}/modules/*\/Database/Migrations,
     * {base}/modules/*\/Migrations.
     *
     * $moduleClasses additionally resolves each class's own directory via
     * reflection (see BaseModule::path()) — this is what makes a module
     * shipped inside a vendor/ Composer package discoverable too, since its
     * directory isn't under {base}/modules/ at all and the glob above would
     * never find it. Pass `array_keys($moduleManager->getModulePaths())`,
     * or just `config('modules.enabled', [])`; both work identically since
     * only the class's file location is used, never its registration state.
     *
     * @param array<array-key, mixed> $moduleClasses Expected to be class-name
     *        strings (e.g. straight from config('modules.enabled')), but not
     *        assumed — anything else is skipped rather than trusted.
     * @return string[]
     */
    public static function discoverPaths(string $basePath, array $moduleClasses = []): array
    {
        // Keyed by a normalized (realpath'd, forward-slash) form so the same
        // directory found twice — once via the glob below, once via a
        // module class's reflection-derived path — is never added twice.
        // A plain in_array() on raw strings isn't reliable here: glob()
        // returns paths built with '/' while ReflectionClass::getFileName()
        // returns whatever separator PHP resolved the include with (often
        // '\' on Windows), so textually-different strings can point at the
        // exact same directory.
        $paths = [];

        $addPath = function (string $dir) use (&$paths): void {
            if (!is_dir($dir)) {
                return;
            }
            $real = realpath($dir) ?: $dir;
            $key = str_replace('\\', '/', $real);
            $paths[$key] = $dir;
        };

        $addPath(rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations');

        $modulesPath = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 'modules';
        if (is_dir($modulesPath)) {
            foreach (['/*/Database/Migrations', '/*/Migrations'] as $pattern) {
                foreach (glob($modulesPath . $pattern) ?: [] as $dir) {
                    $addPath($dir);
                }
            }
        }

        foreach ($moduleClasses as $class) {
            if (!is_string($class) || !class_exists($class)) {
                continue;
            }
            try {
                $dir = dirname((new \ReflectionClass($class))->getFileName());
            } catch (\Throwable) {
                continue;
            }
            foreach (['/Database/Migrations', '/Migrations'] as $suffix) {
                $addPath(rtrim($dir, '/\\') . $suffix);
            }
        }

        return array_values($paths);
    }

    private function getPendingMigrations(string $path): array
    {
        $ran = array_column($this->db->select('SELECT migration FROM migrations'), 'migration');
        $files = $this->getMigrationFiles($path);

        return array_filter($files, function ($file) use ($ran) {
            return !in_array(basename($file, '.php'), $ran, true);
        });
    }

    private function getMigrationFiles(string $path): array
    {
        $files = glob($path . '/*.php') ?: [];
        sort($files);
        return $files;
    }

    /**
     * Resolve a Migration instance from a file.
     *
     * Supports two patterns:
     *   1. Anonymous class  — file ends with `return new class extends Migration { ... };`
     *   2. Named class      — file defines class CreateXxxTable extends Migration
     */
    private function resolveMigration(string $file): Migration
    {
        $contents = (string) file_get_contents($file);

        if (preg_match('/return\s+new\s+class\b/i', $contents)) {
            $instance = require $file;
            if ($instance instanceof Migration) {
                return $instance;
            }
            throw new \RuntimeException(
                "Migration file [{$file}] uses anonymous-class syntax but did not return a Migration instance."
            );
        }

        require_once $file;
        $class = $this->classFromFile($file);

        if (!class_exists($class)) {
            throw new \RuntimeException(
                "Migration class [{$class}] not found after requiring [{$file}]. "
                . "The class name must match the file name, or use the `return new class` pattern."
            );
        }

        return new $class();
    }

    private function classFromFile(string $file): string
    {
        $name = basename($file, '.php');
        // Convert 2024_01_01_000000_create_posts_table → CreatePostsTable
        $parts      = explode('_', $name);
        $classParts = array_slice($parts, 4);
        $className  = implode('', array_map('ucfirst', $classParts));

        // Prepend the namespace declared in the file (handles namespaced migrations)
        $contents = file_get_contents($file);
        if ($contents !== false && preg_match('/^\s*namespace\s+([^\s;]+)/m', $contents, $m)) {
            return $m[1] . '\\' . $className;
        }

        return $className;
    }

    private function getLastBatch(): int
    {
        $row = $this->db->selectOne('SELECT MAX(batch) as b FROM migrations');
        return (int) ($row['b'] ?? 0);
    }

    private function ensureTable(): void
    {
        $this->db->statement(
            "CREATE TABLE IF NOT EXISTS migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration VARCHAR(255) NOT NULL,
                batch INTEGER NOT NULL DEFAULT 0,
                ran_at DATETIME
            )"
        );
    }
}
