<?php

declare(strict_types=1);

namespace Marrow\Validation\Attributes;

use Attribute;

/**
 * Escape hatch for any rule fragment without its own dedicated attribute —
 * the full [rule reference](../../../docs/validation.md#rule-reference) is
 * available this way, not just the handful of attributes shipped here:
 *
 *   #[Rule('unique:users,email')]
 *   #[Rule('starts_with:INV-')]
 *   public string $reference;
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class Rule implements RuleAttribute
{
    public function __construct(public readonly string $rule)
    {
    }

    public function toRule(): string
    {
        return $this->rule;
    }
}
