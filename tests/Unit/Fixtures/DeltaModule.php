<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit\Fixtures;

use Marrow\Module\Attributes\Module;
use Marrow\Module\BaseModule;

#[Module(name: 'delta', imports: [EpsilonModule::class], providers: [], exports: [])]
class DeltaModule extends BaseModule {}
