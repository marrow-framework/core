<?php

declare(strict_types=1);

namespace Marrow\Database\Relations;

use Marrow\Database\Connection;
use Marrow\Database\Model;
use Marrow\Database\ModelQueryBuilder;
use Marrow\Support\Collection;

/**
 * Base class for all ORM relations.
 */
abstract class Relation
{
    public function __construct(
        protected Connection $connection,
        protected Model $related,
        protected string $foreignKey,
        protected string $localKey,
        protected mixed $parentKeyValue
    ) {
    }

    abstract public function getResults(): mixed;

    /**
     * Eager-load this relation for a collection of parent models.
     * Returns a Collection of related models.
     */
    abstract public function eagerLoad(Collection $models, ?callable $constraint): Collection;

    /**
     * After eager loading, match loaded results back onto parent models.
     */
    abstract public function match(Collection $models, Collection $results, string $relation): void;

    /**
     * Load the count of this relation and set it as an attribute on each
     * model. Built via ModelQueryBuilder (rather than raw SQL) so the
     * related model's global scopes — e.g. SoftDeletes — are applied to
     * the count, same as getResults()/eagerLoad() for HasOne/HasMany.
     */
    public function eagerLoadCount(Collection $models, string $countKey): void
    {
        $ids = $models->pluck($this->localKey)->filter()->unique()->toArray();

        if (empty($ids)) {
            return;
        }

        $class = get_class($this->related);
        $qb = (new ModelQueryBuilder($this->connection, $this->related->getTableName(), $class))
            ->select($this->foreignKey, 'COUNT(*) as cnt')
            ->whereIn($this->foreignKey, $ids)
            ->groupBy($this->foreignKey);

        [$sql, $bindings] = $qb->toSql();
        $rows = $this->connection->select($sql, $bindings);

        $map = [];
        foreach ($rows as $row) {
            $map[$row[$this->foreignKey]] = (int) $row['cnt'];
        }

        foreach ($models as $model) {
            $parentId = $model->{$this->localKey};
            $model->setRawAttribute($countKey, $map[$parentId] ?? 0);
        }
    }

    public function getRelatedModel(): Model
    {
        return $this->related;
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    protected function getParentKeys(Collection $models): array
    {
        return $models->pluck($this->localKey)->filter()->unique()->toArray();
    }
}
