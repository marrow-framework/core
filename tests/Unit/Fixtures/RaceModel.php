<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit\Fixtures;

use Marrow\Database\Model;

class RaceModel extends Model
{
    protected string $table = 'race_models';
    protected array $fillable = ['email', 'name'];
}
