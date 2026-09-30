<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit\Fixtures;

class ServiceWithDep
{
    public function __construct(public readonly SimpleService $dep)
    {
    }
}
