<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit\Fixtures;

use Marrow\Attributes\Inject;

class ModuleBServiceNeedingNamedBinding
{
    public function __construct(#[Inject('provider-a.secret')] public mixed $secret)
    {
    }
}
