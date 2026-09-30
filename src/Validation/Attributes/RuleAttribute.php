<?php

declare(strict_types=1);

namespace Marrow\Validation\Attributes;

/**
 * Implemented by every validation attribute — resolveAttributeRules() looks
 * up attributes by this interface (IS_INSTANCEOF), not by a fixed list of
 * classes, so a project's own custom validation attribute works too.
 */
interface RuleAttribute
{
    /** The pipe-rule fragment this attribute contributes, e.g. 'max:255'. */
    public function toRule(): string;
}
