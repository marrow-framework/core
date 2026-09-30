<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit\Fixtures;

use Marrow\Database\Concerns\SoftDeletes;
use Marrow\Database\Model;

class SoftDeleteArticleModel extends Model
{
    use SoftDeletes;

    protected string $table    = 'trashable_articles';
    protected array  $fillable = ['title'];
}
