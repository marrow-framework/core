<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit\Fixtures;

use Marrow\Database\Attributes\Column;
use Marrow\Database\Attributes\Table;
use Marrow\Database\Model;

#[Table('articles')]
#[Column('title')]
#[Column('body')]
#[Column('published', cast: 'bool')]
#[Column('internal_notes', fillable: false, hidden: true)]
class AttributeArticleModel extends Model
{
}
