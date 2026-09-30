<?php

declare(strict_types=1);

namespace Marrow\Database\Relations;

use Marrow\Database\Connection;
use Marrow\Database\Model;
use Marrow\Database\ModelQueryBuilder;
use Marrow\Support\Collection;

/**
 * One-to-many relation: a Post hasMany Comments.
 */
class HasMany extends Relation
{
    public function getResults(): Collection
    {
        if ($this->parentKeyValue === null) {
            return new Collection();
        }

        $class = get_class($this->related);
        return (new ModelQueryBuilder($this->connection, $this->related->getTableName(), $class))
            ->where($this->foreignKey, $this->parentKeyValue)
            ->get();
    }

    public function eagerLoad(Collection $models, ?callable $constraint): Collection
    {
        $keys = $this->getParentKeys($models);
        if (empty($keys)) {
            return new Collection();
        }

        $class = get_class($this->related);
        $qb = (new ModelQueryBuilder($this->connection, $this->related->getTableName(), $class))
            ->whereIn($this->foreignKey, $keys);

        if ($constraint !== null) {
            $constraint($qb);
        }

        return $qb->get();
    }

    public function match(Collection $models, Collection $results, string $relation): void
    {
        $grouped = [];
        foreach ($results as $result) {
            $key = $result->{$this->foreignKey};
            $grouped[$key][] = $result;
        }

        foreach ($models as $model) {
            $key = $model->{$this->localKey};
            $model->setRelation($relation, new Collection($grouped[$key] ?? []));
        }
    }
}
