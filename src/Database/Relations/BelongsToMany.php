<?php

declare(strict_types=1);

namespace Marrow\Database\Relations;

use Marrow\Database\Connection;
use Marrow\Database\Model;
use Marrow\Database\ModelQueryBuilder;
use Marrow\Support\Collection;

/**
 * Many-to-many relation via a pivot table.
 * Post belongsToMany Tag via post_tag.
 */
class BelongsToMany extends Relation
{
    private array $pivotColumns = [];

    public function __construct(
        Connection $connection,
        Model $related,
        private readonly string $pivotTable,
        private readonly string $foreignPivotKey,
        private readonly string $relatedPivotKey,
        mixed $parentKeyValue
    ) {
        parent::__construct($connection, $related, $foreignPivotKey, $related->getKeyName(), $parentKeyValue);
    }

    public function withPivot(string ...$columns): static
    {
        $this->pivotColumns = $columns;
        return $this;
    }

    public function getResults(): Collection
    {
        if ($this->parentKeyValue === null) {
            return new Collection();
        }

        return $this->baseQuery()
            ->where("{$this->pivotTable}.{$this->foreignPivotKey}", $this->parentKeyValue)
            ->get();
    }

    public function eagerLoad(Collection $models, ?callable $constraint): Collection
    {
        $parentKey = $models->isEmpty() ? 'id' : $models->first()->getKeyName();
        $keys = $models->pluck($parentKey)->filter()->unique()->toArray();
        if (empty($keys)) {
            return new Collection();
        }

        $qb = $this->baseQuery(withPivotParent: true)
            ->whereIn("{$this->pivotTable}.{$this->foreignPivotKey}", $keys);

        if ($constraint !== null) {
            $constraint($qb);
        }

        return $qb->get();
    }

    /**
     * Builds the join query via ModelQueryBuilder (rather than raw SQL) so
     * that the related model's global scopes — e.g. SoftDeletes — are
     * applied automatically, same as HasOne/HasMany/BelongsTo.
     */
    private function baseQuery(bool $withPivotParent = false): ModelQueryBuilder
    {
        $table = $this->related->getTableName();
        $relatedKey = $this->related->getKeyName();
        $class = get_class($this->related);

        $columns = ["{$table}.*"];
        foreach ($this->pivotColumns as $c) {
            $columns[] = "{$this->pivotTable}.{$c} as pivot_{$c}";
        }
        if ($withPivotParent) {
            $columns[] = "{$this->pivotTable}.{$this->foreignPivotKey} as _pivot_parent";
        }

        return (new ModelQueryBuilder($this->connection, $table, $class))
            ->select($columns)
            ->join($this->pivotTable, "{$this->pivotTable}.{$this->relatedPivotKey}", '=', "{$table}.{$relatedKey}");
    }

    public function match(Collection $models, Collection $results, string $relation): void
    {
        $grouped = [];
        foreach ($results as $result) {
            $key = $result->_pivot_parent ?? null;
            if ($key !== null) {
                $grouped[$key][] = $result;
            }
        }

        $parentKey = $models->isEmpty() ? 'id' : $models->first()->getKeyName();
        foreach ($models as $model) {
            $model->setRelation($relation, new Collection($grouped[$model->{$parentKey}] ?? []));
        }
    }

    /**
     * Joins to the related table (rather than counting raw pivot rows) so
     * the related model's global scopes — e.g. SoftDeletes — are honoured:
     * a soft-deleted tag no longer inflates the count.
     */
    public function eagerLoadCount(Collection $models, string $countKey): void
    {
        $parentKey = $models->isEmpty() ? 'id' : $models->first()->getKeyName();
        $ids = $models->pluck($parentKey)->filter()->unique()->toArray();

        if (empty($ids)) {
            return;
        }

        $table = $this->related->getTableName();
        $relatedKey = $this->related->getKeyName();
        $class = get_class($this->related);

        $qb = (new ModelQueryBuilder($this->connection, $table, $class))
            ->select("{$this->pivotTable}.{$this->foreignPivotKey} as _k", 'COUNT(*) as cnt')
            ->join($this->pivotTable, "{$this->pivotTable}.{$this->relatedPivotKey}", '=', "{$table}.{$relatedKey}")
            ->whereIn("{$this->pivotTable}.{$this->foreignPivotKey}", $ids)
            ->groupBy("{$this->pivotTable}.{$this->foreignPivotKey}");

        [$sql, $bindings] = $qb->toSql();
        $rows = $this->connection->select($sql, $bindings);

        $map = [];
        foreach ($rows as $row) {
            $map[$row['_k']] = (int) $row['cnt'];
        }

        foreach ($models as $model) {
            $parentId = $model->{$parentKey};
            $model->setRawAttribute($countKey, $map[$parentId] ?? 0);
        }
    }

    // ─────────────────────── Pivot operations ────────────────────────

    public function attach(int|array $ids, array $pivot = []): void
    {
        $ids = (array) $ids;
        foreach ($ids as $id) {
            $data = array_merge([$this->foreignPivotKey => $this->parentKeyValue, $this->relatedPivotKey => $id], $pivot);
            $this->connection->insert($this->pivotTable, $data);
        }
    }

    public function detach(int|array|null $ids = null): void
    {
        $sql = "DELETE FROM {$this->pivotTable} WHERE {$this->foreignPivotKey} = ?";
        $bindings = [$this->parentKeyValue];

        if ($ids !== null) {
            $ids = (array) $ids;
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql .= " AND {$this->relatedPivotKey} IN ({$placeholders})";
            $bindings = array_merge($bindings, $ids);
        }

        $this->connection->statement($sql, $bindings);
    }

    public function sync(array $ids): void
    {
        $this->detach();
        $this->attach($ids);
    }

    public function toggle(array $ids): void
    {
        $current = $this->getResults()->pluck($this->related->getKeyName())->toArray();
        $attach = array_diff($ids, $current);
        $detach = array_intersect($current, $ids);
        $this->attach($attach);
        $this->detach($detach);
    }
}
