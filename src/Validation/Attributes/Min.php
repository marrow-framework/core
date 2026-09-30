<?php

declare(strict_types=1);

namespace Marrow\Validation\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Min implements RuleAttribute
{
    public function __construct(public readonly int|float $value)
    {
    }

    public function toRule(): string
    {
        return "min:{$this->value}";
    }
}
