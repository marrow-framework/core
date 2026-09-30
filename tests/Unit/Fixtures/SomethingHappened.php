<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit\Fixtures;

final class SomethingHappened
{
    public function __construct(public readonly string $payload)
    {
    }
}
