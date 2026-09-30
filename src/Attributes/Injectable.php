<?php

declare(strict_types=1);

namespace Marrow\Attributes;

use Attribute;

/**
 * Marks a class as injectable (auto-resolvable by the Container).
 * Equivalent to NestJS @Injectable().
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Injectable
{
    public function __construct(
        public readonly string $scope = 'transient'
    ) {
    }
}
