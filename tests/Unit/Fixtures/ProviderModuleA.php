<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit\Fixtures;

use Marrow\Module\Attributes\Module;
use Marrow\Module\BaseModule;

#[Module(
    name: 'provider-a',
    imports: [],
    providers: [ModuleAPrivateService::class, ModuleAExportedService::class],
    exports: [ModuleAExportedService::class],
)]
class ProviderModuleA extends BaseModule
{
}
