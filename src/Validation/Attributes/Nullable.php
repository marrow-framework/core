<?php

declare(strict_types=1);

namespace Marrow\Validation\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Nullable implements RuleAttribute
{
    public function toRule(): string
    {
        return 'nullable';
    }
}
