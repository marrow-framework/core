<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit\Fixtures;

use Marrow\Database\Concerns\SoftDeletes;
use Marrow\Database\Model;

class RelationTagModel extends Model
{
    use SoftDeletes;

    protected string $table = 'rel_tags';
    protected array $fillable = ['name'];
}
