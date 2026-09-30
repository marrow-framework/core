<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit\Fixtures;

use Marrow\Database\Concerns\SoftDeletes;
use Marrow\Database\Model;
use Marrow\Database\Relations\BelongsTo;
use Marrow\Database\Relations\BelongsToMany;

class RelationPostModel extends Model
{
    use SoftDeletes;

    protected string $table = 'rel_posts';
    protected array $fillable = ['author_id', 'title'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(RelationAuthorModel::class, 'author_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(RelationTagModel::class, 'rel_post_tag', 'post_id', 'tag_id')
            ->withPivot('sort_order');
    }
}
