<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit\Fixtures;

use Marrow\Database\Model;

class EncryptedFieldModel extends Model
{
    protected string $table = 'secrets';
    protected array $fillable = ['ssn'];
    protected array $casts = ['ssn' => 'encrypted'];
}
