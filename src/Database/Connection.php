<?php

declare(strict_types=1);

namespace Marrow\Database;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use Psr\Log\NullLogger;

/**
 * Wraps Doctrine DBAL with transaction helpers, query logging,
 * and a fluent QueryBuilder entry point.
 */
class Connection
{
    private DbalConnection $dbal;
    private array $queryLog = [];
    private bool $logging;

    public function __construct(array $config)
    {
        $this->logging = (bool) ($_ENV['APP_DEBUG'] ?? false);
        $params = $this->buildParams($config);

        $dbalConfig = new Configuration();

        $this->dbal = DriverManager::getConnection($params, $dbalConfig);
    }

    public function getDbal(): DbalConnection
    {
        return $this->dbal;
    }

    public function table(string $table): QueryBuilder
    {
        return new QueryBuilder($this, $table);
    }

    // ─────────────────────── Raw queries ─────────────────────────────

    public function select(string $sql, array $bindings = []): array
    {
        $start = microtime(true);
        $result = $this->dbal->fetchAllAssociative($sql, $bindings);
        $this->logQuery($sql, $bindings, microtime(true) - $start);
        return $result;
    }

    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $start = microtime(true);
        $result = $this->dbal->fetchAssociative($sql, $bindings) ?: null;
        $this->logQuery($sql, $bindings, microtime(true) - $start);
        return $result;
    }

    public function statement(string $sql, array $bindings = []): int
    {
        $start = microtime(true);
        $result = $this->dbal->executeStatement($sql, $bindings);
        $this->logQuery($sql, $bindings, microtime(true) - $start);
        return $result;
    }

    public function insert(string $table, array $data): int
    {
        $start = microtime(true);
        $this->dbal->insert($table, $data);
        $id = (int) $this->dbal->lastInsertId();
        $this->logQuery("INSERT INTO {$table}", $data, microtime(true) - $start);
        return $id;
    }

    public function update(string $table, array $data, array $criteria): int
    {
        $start = microtime(true);
        $count = $this->dbal->update($table, $data, $criteria);
        $this->logQuery("UPDATE {$table}", $data, microtime(true) - $start);
        return $count;
    }

    public function delete(string $table, array $criteria): int
    {
        $start = microtime(true);
        $count = $this->dbal->delete($table, $criteria);
        $this->logQuery("DELETE FROM {$table}", $criteria, microtime(true) - $start);
        return $count;
    }

    // ─────────────────────── Transactions ────────────────────────────

    public function transaction(callable $callback): mixed
    {
        $this->dbal->beginTransaction();
        try {
            $result = $callback($this);
            $this->dbal->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->dbal->rollBack();
            throw $e;
        }
    }

    public function beginTransaction(): void
    {
        $this->dbal->beginTransaction();
    }

    public function commit(): void
    {
        $this->dbal->commit();
    }

    public function rollback(): void
    {
        $this->dbal->rollBack();
    }

    // ─────────────────────── Schema ──────────────────────────────────

    /** @return \Doctrine\DBAL\Schema\AbstractSchemaManager<\Doctrine\DBAL\Platforms\AbstractPlatform> */
    public function getSchemaManager(): \Doctrine\DBAL\Schema\AbstractSchemaManager
    {
        return $this->dbal->createSchemaManager();
    }

    public function getPlatform(): \Doctrine\DBAL\Platforms\AbstractPlatform
    {
        return $this->dbal->getDatabasePlatform();
    }

    public function lastInsertId(): string|false
    {
        return $this->dbal->lastInsertId();
    }

    // ─────────────────────── Query log ───────────────────────────────

    public function enableQueryLog(): void
    {
        $this->logging = true;
    }

    public function disableQueryLog(): void
    {
        $this->logging = false;
    }

    public function getQueryLog(): array
    {
        return $this->queryLog;
    }

    public function flushQueryLog(): void
    {
        $this->queryLog = [];
    }

    private function logQuery(string $sql, array $bindings, float $time): void
    {
        if ($this->logging) {
            $this->queryLog[] = [
                'sql' => $sql,
                'bindings' => $bindings,
                'time_ms' => round($time * 1000, 2),
            ];
        }
    }

    // ─────────────────────── Connection params ───────────────────────

    private function buildParams(array $cfg): array
    {
        $driver = $cfg['driver'] ?? 'pdo_sqlite';

        // Normalize driver names
        $driver = match ($driver) {
            'mysql' => 'pdo_mysql',
            'pgsql' => 'pdo_pgsql',
            'sqlite' => 'pdo_sqlite',
            default => $driver,
        };

        if ($driver === 'pdo_sqlite') {
            $path = $cfg['database'] ?? 'storage/database.sqlite';
            if (!str_starts_with($path, '/') && !str_contains($path, ':')) {
                $path = base_path($path);
            }
            return ['driver' => 'pdo_sqlite', 'path' => $path];
        }

        return [
            'driver' => $driver,
            'host' => $cfg['host'] ?? '127.0.0.1',
            'port' => (int) ($cfg['port'] ?? 3306),
            'dbname' => $cfg['database'] ?? '',
            'user' => $cfg['username'] ?? '',
            'password' => $cfg['password'] ?? '',
            'charset' => $cfg['charset'] ?? 'utf8mb4',
        ];
    }
}
