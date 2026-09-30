<?php

declare(strict_types=1);

namespace Marrow\Validation\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class In implements RuleAttribute
{
    /** @param array<int, string|int> $values */
    public function __construct(public readonly array $values)
    {
    }

    public function toRule(): string
    {
        return 'in:' . implode(',', $this->values);
    }
}
