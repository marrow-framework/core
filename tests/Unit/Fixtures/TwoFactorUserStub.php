<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit\Fixtures;

use Marrow\Auth\Concerns\HasTwoFactor;

class TwoFactorUserStub
{
    use HasTwoFactor;

    public ?string $two_factor_secret = null;
    public ?string $two_factor_recovery_codes = null;
    public ?string $two_factor_enabled_at = null;
}
