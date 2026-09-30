<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit\Fixtures;

use Marrow\Database\Model;

class ArticleModel extends Model
{
    protected string $table    = 'articles';
    protected array  $fillable = ['title', 'body', 'published'];
    protected array  $casts    = ['published' => 'bool'];
    protected array  $hidden   = ['body'];
}
