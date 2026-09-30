<?php

declare(strict_types=1);

namespace Marrow\Database\Attributes;

use Attribute;

/**
 * Declares one column as mass-assignable/cast/hidden, as an alternative to
 * listing it in the `$fillable`/`$hidden`/`$casts` arrays — stacked on the
 * class itself (repeatable), never on a property:
 *
 *   #[Table('posts')]
 *   #[Column('title')]
 *   #[Column('internal_notes', fillable: false, hidden: true)]
 *   #[Column('published_at', cast: 'datetime')]
 *   class Post extends Model { ... }
 *
 * Deliberately class-level, not property-level: Model's Active Record
 * fields are virtual (stored in an internal $attributes array, read/written
 * through __get()/__set()) — a real declared property with the same name
 * would shadow those magic methods entirely and silently break attribute
 * access, so there is nothing to attach a property attribute to.
 *
 * Merges with, rather than replaces, an explicit $fillable/$hidden/$casts
 * declaration on the model — see Model::applyAttributeConfig().
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class Column
{
    public function __construct(
        public readonly string $name,
        public readonly bool $fillable = true,
        public readonly bool $hidden = false,
        public readonly ?string $cast = null,
    ) {
    }
}
