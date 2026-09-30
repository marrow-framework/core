<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit\Fixtures;

use Marrow\Module\Attributes\Module;
use Marrow\Module\BaseModule;

#[Module(name: 'alpha', imports: [], providers: [], exports: [])]
class AlphaModule extends BaseModule {}
