<?php

declare(strict_types=1);

namespace Marrow\Database\Concerns;

use Marrow\Database\ModelQueryBuilder;
use Marrow\Support\Collection;

/**
 * Soft-delete trait. Add to a Model to enable deleted_at column.
 * Automatically excludes soft-deleted rows from queries via a global scope.
 */
trait SoftDeletes
{
    protected string $deletedAt = 'deleted_at';

    public static function bootSoftDeletes(): void
    {
        // Apply global scope to exclude soft-deleted rows
        static::addGlobalScope('soft_delete', function (ModelQueryBuilder $query) {
            $model = new static();
            $query->whereNull($model->getTableName() . '.' . $model->deletedAt);
        });
    }

    public function delete(): bool
    {
        $this->setRawAttribute($this->deletedAt, date('Y-m-d H:i:s'));
        return $this->save();
    }

    public function forceDelete(): bool
    {
        // Call parent delete (real DELETE)
        $this->setRawAttribute($this->deletedAt, null);
        return parent::delete();
    }

    public function restore(): bool
    {
        $this->setRawAttribute($this->deletedAt, null);
        return $this->save();
    }

    public function trashed(): bool
    {
        return $this->getAttribute($this->deletedAt) !== null;
    }

    public static function withTrashed(): ModelQueryBuilder
    {
        return static::query(['soft_delete']);
    }

    public static function onlyTrashed(): ModelQueryBuilder
    {
        $instance = new static();
        $col = $instance->getTableName() . '.' . $instance->deletedAt;
        return static::query(['soft_delete'])->whereNotNull($col);
    }
}
