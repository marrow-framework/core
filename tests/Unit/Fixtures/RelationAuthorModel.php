<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit\Fixtures;

use Marrow\Database\Model;
use Marrow\Database\Relations\HasMany;

class RelationAuthorModel extends Model
{
    protected string $table = 'rel_authors';
    protected array $fillable = ['country_id', 'name'];

    public function posts(): HasMany
    {
        return $this->hasMany(RelationPostModel::class, 'author_id');
    }
}
