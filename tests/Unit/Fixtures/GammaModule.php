<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit\Fixtures;

use Marrow\Module\Attributes\Module;
use Marrow\Module\BaseModule;

#[Module(name: 'gamma', imports: [BetaModule::class], providers: [], exports: [])]
class GammaModule extends BaseModule {}
