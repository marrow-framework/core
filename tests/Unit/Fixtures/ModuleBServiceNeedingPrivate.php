<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit\Fixtures;

class ModuleBServiceNeedingPrivate
{
    public function __construct(public ModuleAPrivateService $dep)
    {
    }
}
