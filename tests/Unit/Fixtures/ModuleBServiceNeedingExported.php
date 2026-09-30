<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit\Fixtures;

class ModuleBServiceNeedingExported
{
    public function __construct(public ModuleAExportedService $dep)
    {
    }
}
