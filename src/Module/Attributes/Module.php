<?php

declare(strict_types=1);

namespace Marrow\Module\Attributes;

use Attribute;

/**
 * Declares a class as an HMVC module.
 *
 * - imports:   other module classes this module depends on
 * - providers: services bound in the container (private by default)
 * - exports:   providers made accessible to importing modules
 * - commands:  console command classes auto-registered for this module
 * - listeners: [EventClass => [ListenerClass, ...]] event wiring
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Module
{
    public function __construct(
        public readonly string $name,
        public readonly array $imports = [],
        public readonly array $providers = [],
        public readonly array $exports = [],
        public readonly array $commands = [],
        public readonly array $listeners = [],
    ) {
    }
}
