<?php

declare(strict_types=1);

namespace Marrow\Database;

use Marrow\Support\Collection;
use Marrow\Support\Paginator;

/**
 * Fluent QueryBuilder over Doctrine DBAL.
 * Returns Collection for multi-row results, stdClass for single rows.
 */
class QueryBuilder
{
    private array $wheres = [];
    private array $bindings = [];
    private array $orders = [];
    private array $groups = [];
    private array $havings = [];
    private array $joins = [];
    private array $columns = ['*'];
    private ?int $limitVal = null;
    private ?int $offsetVal = null;
    private bool $distinct = false;

    public function __construct(
        protected readonly Connection $connection,
        private readonly string $table
    ) {
    }

    // ─────────────────────── Columns ─────────────────────────────────

    public function select(string|array ...$columns): static
    {
        $this->columns = is_array($columns[0] ?? null) ? $columns[0] : $columns;
        return $this;
    }

    public function distinct(): static
    {
        $this->distinct = true;
        return $this;
    }

    // ─────────────────────── Conditions ──────────────────────────────

    public function where(string $column, mixed $operator, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }
        if ($value === null) {
            $isNot = in_array(strtolower((string) $operator), ['!=', '<>', 'is not'], true);
            $this->wheres[] = "{$column} " . ($isNot ? 'IS NOT NULL' : 'IS NULL');
            return $this;
        }
        $this->wheres[] = "{$column} {$operator} ?";
        $this->bindings[] = $value;
        return $this;
    }

    public function orWhere(string $column, mixed $operator, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }
        if ($value === null) {
            $isNot = in_array(strtolower((string) $operator), ['!=', '<>', 'is not'], true);
            $this->wheres[] = "OR {$column} " . ($isNot ? 'IS NOT NULL' : 'IS NULL');
            return $this;
        }
        $this->wheres[] = "OR {$column} {$operator} ?";
        $this->bindings[] = $value;
        return $this;
    }

    public function whereIn(string $column, array $values): static
    {
        if (empty($values)) {
            $this->wheres[] = '1 = 0'; // Always false
            return $this;
        }
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $this->wheres[] = "{$column} IN ({$placeholders})";
        $this->bindings = array_merge($this->bindings, array_values($values));
        return $this;
    }

    public function whereNotIn(string $column, array $values): static
    {
        if (empty($values)) {
            return $this;
        }
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $this->wheres[] = "{$column} NOT IN ({$placeholders})";
        $this->bindings = array_merge($this->bindings, array_values($values));
        return $this;
    }

    public function whereNull(string $column): static
    {
        $this->wheres[] = "{$column} IS NULL";
        return $this;
    }

    public function whereNotNull(string $column): static
    {
        $this->wheres[] = "{$column} IS NOT NULL";
        return $this;
    }

    public function whereBetween(string $column, mixed $min, mixed $max): static
    {
        $this->wheres[] = "{$column} BETWEEN ? AND ?";
        $this->bindings[] = $min;
        $this->bindings[] = $max;
        return $this;
    }

    public function whereLike(string $column, string $value): static
    {
        $this->wheres[] = "{$column} LIKE ?";
        $this->bindings[] = $value;
        return $this;
    }

    public function whereExists(callable $subquery): static
    {
        $sub = new static($this->connection, $this->table);
        $subquery($sub);
        [$sql, $bindings] = $sub->toSql();
        $this->wheres[] = "EXISTS ({$sql})";
        $this->bindings = array_merge($this->bindings, $bindings);
        return $this;
    }

    public function when(bool $condition, callable $callback, ?callable $else = null): static
    {
        if ($condition) {
            $callback($this);
        } elseif ($else !== null) {
            $else($this);
        }
        return $this;
    }

    // ─────────────────────── Joins ───────────────────────────────────

    public function join(string $table, string $first, string $operator, string $second): static
    {
        $this->joins[] = "INNER JOIN {$table} ON {$first} {$operator} {$second}";
        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): static
    {
        $this->joins[] = "LEFT JOIN {$table} ON {$first} {$operator} {$second}";
        return $this;
    }

    // ─────────────────────── Ordering / grouping ─────────────────────

    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $this->orders[] = "{$column} {$direction}";
        return $this;
    }

    public function latest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'DESC');
    }

    public function oldest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'ASC');
    }

    public function groupBy(string ...$columns): static
    {
        foreach ($columns as $col) {
            $this->groups[] = $col;
        }
        return $this;
    }

    public function having(string $column, mixed $operator, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }
        if ($value === null) {
            $isNot = in_array(strtolower((string) $operator), ['!=', '<>', 'is not'], true);
            $this->havings[] = "{$column} " . ($isNot ? 'IS NOT NULL' : 'IS NULL');
            return $this;
        }
        $this->havings[] = "{$column} {$operator} ?";
        $this->bindings[] = $value;
        return $this;
    }

    // ─────────────────────── Limits ──────────────────────────────────

    public function limit(int $n): static
    {
        $this->limitVal = $n;
        return $this;
    }

    public function take(int $n): static
    {
        return $this->limit($n);
    }

    public function offset(int $n): static
    {
        $this->offsetVal = $n;
        return $this;
    }

    public function skip(int $n): static
    {
        return $this->offset($n);
    }

    public function forPage(int $page, int $perPage = 15): static
    {
        return $this->offset(($page - 1) * $perPage)->limit($perPage);
    }

    // ─────────────────────── Fetching ────────────────────────────────

    public function get(): Collection
    {
        [$sql, $bindings] = $this->toSql();
        $rows = $this->connection->select($sql, $bindings);
        return new Collection(array_map(fn($r) => (object) $r, $rows));
    }

    public function first(): ?object
    {
        return $this->limit(1)->get()->first();
    }

    public function find(int|string $id, string $column = 'id'): ?object
    {
        return $this->where($column, $id)->first();
    }

    public function count(string $column = '*'): int
    {
        return (int) $this->aggregate("COUNT({$column})");
    }

    public function sum(string $column): float
    {
        return (float) $this->aggregate("SUM({$column})");
    }

    public function avg(string $column): float
    {
        return (float) $this->aggregate("AVG({$column})");
    }

    public function min(string $column): mixed
    {
        return $this->castNumericAggregate($this->aggregate("MIN({$column})"));
    }

    public function max(string $column): mixed
    {
        return $this->castNumericAggregate($this->aggregate("MAX({$column})"));
    }

    /**
     * sum()/avg() are always numeric by definition and cast unconditionally.
     * min()/max() are meaningful on any orderable column (numbers, dates,
     * strings), so casting unconditionally to float would silently corrupt a
     * non-numeric result (e.g. MIN(created_at) becoming 0.0) — only cast when
     * the raw PDO value actually looks numeric.
     */
    private function castNumericAggregate(mixed $value): mixed
    {
        return is_numeric($value) ? (float) $value : $value;
    }

    public function pluck(string $column): array
    {
        return $this->get()->pluck($column)->toArray();
    }

    public function chunk(int $size, callable $callback): void
    {
        $page = 1;
        do {
            $results = $this->forPage($page, $size)->get();
            if ($results->isEmpty()) {
                break;
            }
            if ($callback($results) === false) {
                break;
            }
            $page++;
        } while ($results->count() === $size);
    }

    // ─────────────────────── Pagination ──────────────────────────────

    public function paginate(int $perPage = 15, int $page = 1): Paginator
    {
        $total = $this->count();
        $items = $this->forPage($page, $perPage)->get();
        return new Paginator($items, $total, $perPage, $page);
    }

    // ─────────────────────── Write ops ───────────────────────────────

    public function insertGetId(array $data): int
    {
        return $this->connection->insert($this->table, $data);
    }

    public function insertMany(array $rows): void
    {
        foreach ($rows as $row) {
            $this->connection->insert($this->table, $row);
        }
    }

    public function updateWhere(array $data): int
    {
        $setClauses = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($data)));
        $setValues = array_values($data);
        [$whereSql, $whereBindings] = $this->buildWhere();

        if (empty($setClauses)) {
            return 0;
        }

        $sql = "UPDATE {$this->table} SET {$setClauses}" . $whereSql;
        $bindings = array_merge($setValues, $whereBindings);

        return $this->connection->statement($sql, $bindings);
    }

    public function deleteWhere(): int
    {
        [$whereSql, $bindings] = $this->buildWhere();
        $sql = "DELETE FROM {$this->table}" . $whereSql;
        return $this->connection->statement($sql, $bindings);
    }

    // ─────────────────────── SQL generation ──────────────────────────

    public function toSql(): array
    {
        $distinct = $this->distinct ? 'DISTINCT ' : '';
        $cols = implode(', ', $this->columns);
        $sql = "SELECT {$distinct}{$cols} FROM {$this->table}";

        foreach ($this->joins as $join) {
            $sql .= " {$join}";
        }

        [$whereSql, $whereBindings] = $this->buildWhere();
        $sql .= $whereSql;
        $bindings = $whereBindings;

        if (!empty($this->groups)) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groups);
        }

        if (!empty($this->havings)) {
            $sql .= ' HAVING ' . $this->buildHaving();
        }

        if (!empty($this->orders)) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }

        if ($this->limitVal !== null) {
            $sql .= " LIMIT {$this->limitVal}";
        }

        if ($this->offsetVal !== null) {
            $sql .= " OFFSET {$this->offsetVal}";
        }

        return [$sql, $bindings];
    }

    private function buildWhere(): array
    {
        if (empty($this->wheres)) {
            return ['', []];
        }

        $clauses = [];
        $bindings = $this->bindings;
        $i = 0;

        foreach ($this->wheres as $where) {
            if ($i === 0) {
                $clauses[] = str_starts_with($where, 'OR ') ? substr($where, 3) : $where;
            } else {
                $clauses[] = str_starts_with($where, 'OR ') ? $where : "AND {$where}";
            }
            $i++;
        }

        return [' WHERE ' . implode(' ', $clauses), $bindings];
    }

    private function buildHaving(): string
    {
        return implode(' AND ', $this->havings);
    }

    private function aggregate(string $expr): mixed
    {
        $original = $this->columns;
        $this->columns = [$expr . ' as _agg'];
        [$sql, $bindings] = $this->toSql();
        $this->columns = $original;

        $row = $this->connection->selectOne($sql, $bindings);
        return $row['_agg'] ?? null;
    }
}
