<?php

declare(strict_types=1);

namespace Marrow\Database\Relations;

use Marrow\Database\ModelQueryBuilder;
use Marrow\Database\Model;
use Marrow\Support\Collection;

/**
 * One-to-one relation: a User hasOne Profile.
 */
class HasOne extends Relation
{
    public function getResults(): ?Model
    {
        if ($this->parentKeyValue === null) {
            return null;
        }

        $class = get_class($this->related);
        return (new ModelQueryBuilder($this->connection, $this->related->getTableName(), $class))
            ->where($this->foreignKey, $this->parentKeyValue)
            ->first();
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
        $map = [];
        foreach ($results as $result) {
            $map[$result->{$this->foreignKey}] = $result;
        }

        foreach ($models as $model) {
            $model->setRelation($relation, $map[$model->{$this->localKey}] ?? null);
        }
    }
}
