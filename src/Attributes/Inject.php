<?php

declare(strict_types=1);

namespace Marrow\Attributes;

use Attribute;

/**
 * Injects a named binding or config dot-notation value into a constructor parameter.
 * Usage: #[Inject('config.app.name')] or #[Inject('some.binding.key')]
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Inject
{
    public function __construct(
        public readonly string $key
    ) {
    }
}
