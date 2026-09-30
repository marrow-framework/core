<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit\Fixtures;

use Marrow\Module\Attributes\Module;
use Marrow\Module\BaseModule;

#[Module(
    name: 'consumer-b',
    imports: [ProviderModuleA::class],
    providers: [
        ModuleBServiceNeedingPrivate::class,
        ModuleBServiceNeedingExported::class,
        ModuleBController::class,
        ModuleBServiceNeedingNamedBinding::class,
    ],
    exports: [],
)]
class ConsumerModuleB extends BaseModule
{
}
