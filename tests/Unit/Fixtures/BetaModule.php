<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit\Fixtures;

use Marrow\Module\Attributes\Module;
use Marrow\Module\BaseModule;

#[Module(name: 'beta', imports: [AlphaModule::class], providers: [], exports: [])]
class BetaModule extends BaseModule {}
