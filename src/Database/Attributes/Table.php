<?php

declare(strict_types=1);

namespace Marrow\Database\Attributes;

use Attribute;

/**
 * Declares a Model's table name, as an alternative to `protected string
 * $table = '...'`:
 *
 *   #[Table('posts')]
 *   class Post extends Model { ... }
 *
 * Ignored if the model also sets `$table` directly — that property wins.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Table
{
    public function __construct(public readonly string $name)
    {
    }
}
