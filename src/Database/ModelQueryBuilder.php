<?php

declare(strict_types=1);

namespace Marrow\Database;

use Marrow\Support\Collection;
use Marrow\Support\Paginator;

/**
 * Extends QueryBuilder to hydrate rows as Model instances,
 * apply global scopes, and handle eager loading.
 */
class ModelQueryBuilder extends QueryBuilder
{
    private array $eagerLoads = [];
    private array $withCounts = [];

    public function __construct(
        Connection $connection,
        string $table,
        private readonly string $modelClass,
        private readonly array $withoutGlobalScopes = []
    ) {
        parent::__construct($connection, $table);
        $this->applyGlobalScopes();
    }

    public function with(string|array ...$relations): static
    {
        $rels = is_array($relations[0] ?? null) ? $relations[0] : $relations;

        foreach ($rels as $relation => $constraint) {
            if (is_int($relation)) {
                // No constraint, just relation name
                $this->eagerLoads[$constraint] = null;
            } else {
                // Relation with a constraint callback
                $this->eagerLoads[$relation] = $constraint;
            }
        }

        return $this;
    }

    public function withCount(string|array ...$relations): static
    {
        $rels = is_array($relations[0] ?? null) ? $relations[0] : $relations;
        foreach ($rels as $rel) {
            $this->withCounts[] = $rel;
        }
        return $this;
    }

    public function get(): Collection
    {
        [$sql, $bindings] = $this->toSql();
        $rows = $this->getConnection()->select($sql, $bindings);

        $models = $this->hydrate($rows);

        if (!$models->isEmpty() && !empty($this->eagerLoads)) {
            $this->loadEagerRelations($models);
        }

        if (!$models->isEmpty() && !empty($this->withCounts)) {
            $this->loadCounts($models);
        }

        return $models;
    }

    public function first(): ?Model
    {
        return $this->limit(1)->get()->first();
    }

    public function paginate(int $perPage = 15, int $page = 1): Paginator
    {
        $total = $this->count();
        $items = $this->forPage($page, $perPage)->get();
        return new Paginator($items, $total, $perPage, $page);
    }

    private function hydrate(array $rows): Collection
    {
        $models = [];
        foreach ($rows as $row) {
            /** @var Model $model */
            $model = new $this->modelClass();
            $model->setRawAttributes($row);
            $model->setOriginal($row);
            $model->setExists(true);
            $models[] = $model;
        }
        return new Collection($models);
    }

    private function applyGlobalScopes(): void
    {
        $scopes = ($this->modelClass)::getGlobalScopes();
        foreach ($scopes as $name => $scope) {
            if (in_array($name, $this->withoutGlobalScopes, true)) {
                continue;
            }
            $scope($this);
        }
    }

    private function getConnection(): Connection
    {
        return $this->connection;
    }

    private function loadEagerRelations(Collection $models): void
    {
        foreach ($this->eagerLoads as $relationPath => $constraint) {
            // Support dot notation: 'author.profile'
            $parts = explode('.', $relationPath, 2);
            $relation = $parts[0];
            $nested = $parts[1] ?? null;

            $this->eagerLoadRelation($models, $relation, $constraint, $nested);
        }
    }

    private function eagerLoadRelation(Collection $models, string $relation, ?callable $constraint, ?string $nested): void
    {
        if ($models->isEmpty()) {
            return;
        }

        // Get first model to introspect the relation
        $first = $models->first();
        if (!method_exists($first, $relation)) {
            return;
        }

        $relInstance = $first->$relation();
        if (!($relInstance instanceof \Marrow\Database\Relations\Relation)) {
            return;
        }

        // Eager-load via WHERE IN
        $results = $relInstance->eagerLoad($models, $constraint);

        // If nested, continue eager loading on the results
        if ($nested !== null && $results->isNotEmpty()) {
            // recursively eager load
            $nestedLoader = new self(
                $relInstance->getConnection(),
                $relInstance->getRelatedModel()->getTableName(),
                get_class($results->first())
            );
            $nestedLoader->eagerLoads = [$nested => null];
            $nestedLoader->loadEagerRelations($results);
        }

        // Match and set relations
        $relInstance->match($models, $results, $relation);
    }

    private function loadCounts(Collection $models): void
    {
        // Simplified: load count via subquery for each relation
        // In a full implementation this would be more sophisticated
        $first = $models->first();
        if ($first === null) {
            return;
        }

        foreach ($this->withCounts as $relation) {
            if (!method_exists($first, $relation)) {
                continue;
            }
            $relInstance = $first->$relation();
            if (!($relInstance instanceof \Marrow\Database\Relations\Relation)) {
                continue;
            }

            $relInstance->eagerLoadCount($models, $relation . '_count');
        }
    }
}
