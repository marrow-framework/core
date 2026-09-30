<?php

declare(strict_types=1);

namespace Marrow\Database\Schema;

use Marrow\Application;
use Marrow\Database\Connection;

/**
 * Static entry point for schema operations — create, alter, drop tables.
 *
 * Dialect support: SQLite · MySQL / MariaDB · PostgreSQL · generic fallback.
 * All SQL is generated from the Table definition without going through Doctrine's DDL
 * compiler, so every dialect quirk is handled explicitly here.
 */
class Schema
{
    // ── Public API ───────────────────────────────────────────────────

    public static function create(string $table, callable $callback): void
    {
        $definition = new Table($table);
        $callback($definition);
        self::buildTable($definition, false);
    }

    public static function table(string $table, callable $callback): void
    {
        $definition = new Table($table);
        $callback($definition);
        self::buildTable($definition, true);
    }

    public static function drop(string $table): void
    {
        $sm = self::connection()->getSchemaManager();
        if ($sm->tablesExist([$table])) {
            $sm->dropTable($table);
        }
    }

    public static function dropIfExists(string $table): void
    {
        self::drop($table);
    }

    public static function hasTable(string $table): bool
    {
        return self::connection()->getSchemaManager()->tablesExist([$table]);
    }

    public static function hasColumn(string $table, string $column): bool
    {
        $cols = self::connection()->getSchemaManager()->listTableColumns($table);
        return isset($cols[strtolower($column)]);
    }

    // ── Dialect helpers ──────────────────────────────────────────────

    /**
     * Returns a short dialect key from the platform class name.
     * Supported: 'sqlite' | 'mysql' | 'pgsql' | 'generic'
     */
    private static function dialect(mixed $platform): string
    {
        $cls = strtolower(class_basename(get_class($platform)));
        if (str_contains($cls, 'sqlite'))                              return 'sqlite';
        if (str_contains($cls, 'mysql') || str_contains($cls, 'maria')) return 'mysql';
        if (str_contains($cls, 'postgre') || str_contains($cls, 'pgsql')) return 'pgsql';
        return 'generic';
    }

    /** Identifier quoting character for this dialect. */
    private static function q(string $dialect): string
    {
        return $dialect === 'mysql' ? '`' : '"';
    }

    // ── Table builder ────────────────────────────────────────────────

    private static function buildTable(Table $table, bool $alter): void
    {
        $table->finalizeForeignIds();

        $conn      = self::connection();
        $platform  = $conn->getPlatform();
        $sm        = $conn->getSchemaManager();
        $tableName = $table->getTableName();
        $dialect   = self::dialect($platform);
        $q         = self::q($dialect);

        // ── ALTER: add / drop columns ─────────────────────────────────
        if ($alter && $sm->tablesExist([$tableName])) {
            foreach ($table->getColumns() as $col) {
                if ($col instanceof ColumnDefinition) {
                    $conn->statement(
                        self::buildAddColumnSql($tableName, $col->name, $col->type, $col->getOptions(), $dialect)
                    );
                }
            }
            foreach ($table->getDrops() as $dropCol) {
                $conn->statement("ALTER TABLE {$q}{$tableName}{$q} DROP COLUMN {$q}{$dropCol}{$q}");
            }
            return;
        }

        // ── CREATE TABLE ──────────────────────────────────────────────
        $cols       = [];
        $skipPkCols = [];   // PK already embedded in column def (SQLite AUTOINCREMENT)
        $extraSqls  = [];   // Statements executed after CREATE TABLE (indexes)

        // Columns
        foreach ($table->getColumns() as $col) {
            if (is_array($col)) {
                // id() / bigIncrements() — auto-increment primary key
                $autoInc = $col['options']['autoincrement'] ?? false;
                $nullable = !($col['options']['notnull'] ?? true);

                if ($autoInc) {
                    $cols[] = self::buildAutoIncrementCol($col['name'], $dialect, $q, $skipPkCols);
                } else {
                    $typeSql = self::mapType($col['type'], $col['options'], $dialect);
                    $colSql  = "{$q}{$col['name']}{$q} {$typeSql}";
                    $colSql .= $nullable ? ' NULL' : ' NOT NULL';
                    if (!$nullable && array_key_exists('default', $col['options'])) {
                        $colSql .= ' DEFAULT ' . self::quoteDefault($col['options']['default']);
                    }
                    $cols[] = $colSql;
                }
            } elseif ($col instanceof ColumnDefinition) {
                $opts    = $col->getOptions();
                $typeSql = self::mapType($col->type, $opts, $dialect);
                $nullable = !($opts['notnull'] ?? true);

                $colSql  = "{$q}{$col->name}{$q} {$typeSql}";
                $colSql .= $nullable ? ' NULL' : ' NOT NULL';
                if (array_key_exists('default', $opts)) {
                    $colSql .= ' DEFAULT ' . self::quoteDefault($opts['default']);
                }
                $cols[] = $colSql;
            }
        }

        // Constraints and indices
        foreach ($table->getIndices() as $idx) {
            $idxCols = implode(', ', array_map(fn($c) => "{$q}{$c}{$q}", $idx['columns']));

            if ($idx['type'] === 'primary') {
                // Skip when the PK is already embedded in the column definition (SQLite)
                if (!empty(array_intersect($idx['columns'], $skipPkCols))) {
                    continue;
                }
                $cols[] = "PRIMARY KEY ({$idxCols})";

            } elseif ($idx['type'] === 'unique') {
                // MySQL uses UNIQUE KEY (...); all others use UNIQUE (...)
                $cols[] = $dialect === 'mysql'
                    ? "UNIQUE KEY ({$idxCols})"
                    : "UNIQUE ({$idxCols})";

            } elseif ($idx['type'] === 'index') {
                if ($dialect === 'mysql') {
                    // MySQL supports inline INDEX inside CREATE TABLE
                    $cols[] = "INDEX ({$idxCols})";
                } else {
                    // SQLite and PostgreSQL require a separate CREATE INDEX statement
                    $idxName     = 'idx_' . $tableName . '_' . implode('_', $idx['columns']);
                    $extraSqls[] = "CREATE INDEX IF NOT EXISTS {$q}{$idxName}{$q}"
                                 . " ON {$q}{$tableName}{$q} ({$idxCols})";
                }
            }
        }

        // Foreign keys
        // SQLite ignores FK constraints unless PRAGMA foreign_keys = ON is set,
        // and they cannot be added inline anyway when using the simple CREATE TABLE path.
        // PostgreSQL and MySQL support inline FOREIGN KEY.
        if ($dialect !== 'sqlite') {
            foreach ($table->getForeigns() as $fk) {
                $fkSql = "FOREIGN KEY ({$q}{$fk['column']}{$q})"
                       . " REFERENCES {$q}{$fk['table']}{$q} ({$q}{$fk['ref_column']}{$q})";
                if ($fk['on_delete']) {
                    $fkSql .= " ON DELETE {$fk['on_delete']}";
                }
                $cols[] = $fkSql;
            }
        }

        // Closing clause
        $suffix = $dialect === 'mysql'
            ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            : '';

        $sql = "CREATE TABLE IF NOT EXISTS {$q}{$tableName}{$q} (\n  "
             . implode(",\n  ", $cols)
             . "\n){$suffix}";

        $conn->statement($sql);

        foreach ($extraSqls as $extraSql) {
            $conn->statement($extraSql);
        }
    }

    /**
     * Build the auto-increment primary-key column definition.
     * Each dialect has its own syntax for this.
     *
     * @param string[] $skipPkCols  Accumulates columns whose PK is embedded (SQLite).
     */
    private static function buildAutoIncrementCol(
        string $name,
        string $dialect,
        string $q,
        array  &$skipPkCols
    ): string {
        switch ($dialect) {
            case 'sqlite':
                // INTEGER PRIMARY KEY AUTOINCREMENT must be declared on the column;
                // a separate PRIMARY KEY constraint is not allowed alongside it.
                $skipPkCols[] = $name;
                return "{$q}{$name}{$q} INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL";

            case 'pgsql':
                // BIGSERIAL expands to BIGINT + an implicit sequence.
                // The PRIMARY KEY constraint is added separately.
                return "{$q}{$name}{$q} BIGSERIAL NOT NULL";

            case 'mysql':
            default:
                // BIGINT UNSIGNED NOT NULL AUTO_INCREMENT; PRIMARY KEY added separately.
                return "{$q}{$name}{$q} BIGINT UNSIGNED NOT NULL AUTO_INCREMENT";
        }
    }

    // ── Column type mapping ──────────────────────────────────────────

    private static function mapType(string $type, array $opts, string $dialect): string
    {
        $unsigned = $opts['unsigned'] ?? false;

        return match ($type) {
            'bigint' => match ($dialect) {
                'sqlite'  => 'INTEGER',
                'mysql'   => $unsigned ? 'BIGINT UNSIGNED' : 'BIGINT',
                default   => 'BIGINT',
            },
            'integer' => match ($dialect) {
                'sqlite'  => 'INTEGER',
                'mysql'   => $unsigned ? 'INT UNSIGNED' : 'INT',
                default   => 'INTEGER',
            },
            'string'  => 'VARCHAR(' . ($opts['length'] ?? 255) . ')',
            'text'    => 'TEXT',
            'boolean' => match ($dialect) {
                'sqlite'  => 'INTEGER',
                'mysql'   => 'TINYINT(1)',
                default   => 'BOOLEAN',
            },
            'float', 'decimal' => 'DECIMAL(' . ($opts['precision'] ?? 8) . ',' . ($opts['scale'] ?? 2) . ')',
            'json' => match ($dialect) {
                'sqlite'  => 'TEXT',
                'pgsql'   => 'JSONB',
                default   => 'JSON',
            },
            'timestamp' => match ($dialect) {
                'sqlite'  => 'TEXT',
                'pgsql'   => 'TIMESTAMPTZ',
                default   => 'TIMESTAMP',
            },
            'datetime_mutable', 'datetime' => match ($dialect) {
                'sqlite'  => 'TEXT',
                'pgsql'   => 'TIMESTAMP',
                default   => 'DATETIME',
            },
            'date_mutable', 'date' => match ($dialect) {
                'sqlite'  => 'TEXT',
                default   => 'DATE',
            },
            default => strtoupper($type),
        };
    }

    // ── ALTER TABLE helper ───────────────────────────────────────────

    private static function buildAddColumnSql(
        string $table,
        string $col,
        string $type,
        array  $opts,
        string $dialect
    ): string {
        $q        = self::q($dialect);
        $typeSql  = self::mapType($type, $opts, $dialect);
        $nullable = !($opts['notnull'] ?? true);
        $null     = $nullable ? 'NULL' : 'NOT NULL';

        $sql = "ALTER TABLE {$q}{$table}{$q} ADD COLUMN {$q}{$col}{$q} {$typeSql} {$null}";

        if (array_key_exists('default', $opts)) {
            $sql .= ' DEFAULT ' . self::quoteDefault($opts['default']);
        }

        if ($dialect === 'mysql' && isset($opts['after'])) {
            $sql .= " AFTER {$q}{$opts['after']}{$q}";
        }

        return $sql;
    }

    // ── Default value quoting ────────────────────────────────────────

    private static function quoteDefault(mixed $value): string
    {
        if ($value === null)                    return 'NULL';
        if ($value === 'CURRENT_TIMESTAMP')     return 'CURRENT_TIMESTAMP';
        if (is_bool($value))                    return $value ? '1' : '0';
        if (is_int($value) || is_float($value)) return (string) $value;
        return "'" . addslashes((string) $value) . "'";
    }

    // ── Connection ───────────────────────────────────────────────────

    private static function connection(): Connection
    {
        return Application::getInstance()->getContainer()->make(Connection::class);
    }
}
