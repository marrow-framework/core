<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit\Fixtures;

/** Simulates a Router-resolved controller: constructed with no explicit
 *  callerModule, relying purely on its own bindingOwners entry to seed the
 *  module context for everything it transitively depends on. */
class ModuleBController
{
    public function __construct(public ModuleBServiceNeedingExported $service)
    {
    }
}
