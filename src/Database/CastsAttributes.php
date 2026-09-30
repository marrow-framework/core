<?php

declare(strict_types=1);

namespace Marrow\Database;

/**
 * Interface for custom attribute casts.
 * Implement this to define type-safe custom cast classes.
 */
interface CastsAttributes
{
    public function get(Model $model, string $key, mixed $value): mixed;
    public function set(Model $model, string $key, mixed $value): mixed;
}
