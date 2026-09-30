<?php

declare(strict_types=1);

namespace Marrow\Database\Relations;

use Marrow\Database\Connection;
use Marrow\Database\Model;
use Marrow\Database\ModelQueryBuilder;
use Marrow\Support\Collection;

/**
 * Has-many-through: Country hasManyThrough Post through User.
 */
class HasManyThrough extends Relation
{
    public function __construct(
        Connection $connection,
        Model $related,
        private readonly Model $through,
        string $firstKey,
        private readonly string $secondKey,
        string $localKey,
        private readonly string $secondLocalKey,
        mixed $parentKeyValue
    ) {
        parent::__construct($connection, $related, $firstKey, $localKey, $parentKeyValue);
    }

    public function getResults(): Collection
    {
        if ($this->parentKeyValue === null) {
            return new Collection();
        }

        return $this->baseQuery()
            ->where("{$this->through->getTableName()}.{$this->foreignKey}", $this->parentKeyValue)
            ->get();
    }

    public function eagerLoad(Collection $models, ?callable $constraint): Collection
    {
        $keys = $this->getParentKeys($models);
        if (empty($keys)) {
            return new Collection();
        }

        $qb = $this->baseQuery(withThroughParent: true)
            ->whereIn("{$this->through->getTableName()}.{$this->foreignKey}", $keys);

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
    private function baseQuery(bool $withThroughParent = false): ModelQueryBuilder
    {
        $relatedTable = $this->related->getTableName();
        $throughTable = $this->through->getTableName();
        $class = get_class($this->related);

        $columns = ["{$relatedTable}.*"];
        if ($withThroughParent) {
            $columns[] = "{$throughTable}.{$this->foreignKey} as _through_parent";
        }

        return (new ModelQueryBuilder($this->connection, $relatedTable, $class))
            ->select($columns)
            ->join($throughTable, "{$throughTable}.{$this->secondLocalKey}", '=', "{$relatedTable}.{$this->secondKey}");
    }

    public function match(Collection $models, Collection $results, string $relation): void
    {
        $grouped = [];
        foreach ($results as $result) {
            $key = $result->_through_parent ?? null;
            if ($key !== null) {
                $grouped[$key][] = $result;
            }
        }

        foreach ($models as $model) {
            $model->setRelation($relation, new Collection($grouped[$model->{$this->localKey}] ?? []));
        }
    }

    /**
     * Overrides the base Relation::eagerLoadCount(), which assumes
     * $foreignKey lives directly on the related table — for a "through"
     * relation it lives on the through table instead, so the base
     * implementation would generate SQL referencing a column that doesn't
     * exist on $relatedTable. Built via ModelQueryBuilder (rather than raw
     * SQL) so the related model's global scopes — e.g. SoftDeletes — are
     * honoured in the count, same as getResults()/eagerLoad().
     */
    public function eagerLoadCount(Collection $models, string $countKey): void
    {
        $keys = $this->getParentKeys($models);
        if (empty($keys)) {
            return;
        }

        $relatedTable = $this->related->getTableName();
        $throughTable = $this->through->getTableName();
        $class = get_class($this->related);

        $qb = (new ModelQueryBuilder($this->connection, $relatedTable, $class))
            ->select("{$throughTable}.{$this->foreignKey} as _k", 'COUNT(*) as cnt')
            ->join($throughTable, "{$throughTable}.{$this->secondLocalKey}", '=', "{$relatedTable}.{$this->secondKey}")
            ->whereIn("{$throughTable}.{$this->foreignKey}", $keys)
            ->groupBy("{$throughTable}.{$this->foreignKey}");

        [$sql, $bindings] = $qb->toSql();
        $rows = $this->connection->select($sql, $bindings);

        $map = [];
        foreach ($rows as $row) {
            $map[$row['_k']] = (int) $row['cnt'];
        }

        foreach ($models as $model) {
            $parentId = $model->{$this->localKey};
            $model->setRawAttribute($countKey, $map[$parentId] ?? 0);
        }
    }
}
