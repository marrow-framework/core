<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Marrow\Validation\ValidatorFactory;

// ── Tests ─────────────────────────────────────────────────────────────────────
//
// ValidatorFactory::make() used to swallow a Connection-resolution failure and
// build a ValidatorInstance with $db = null — which makes every unique:/exists:
// rule silently pass (see ValidatorInstance::validateUnique()/validateExists()).
// It must now let that failure propagate instead of masking it.

test('make() propagates a Connection resolution failure instead of silently building a validator without a database', function () {
    // No Application has been booted in this process, so Application::getInstance()
    // cannot resolve Connection — make() must surface that, not swallow it.
    $factory = new ValidatorFactory();

    expect(fn () => $factory->make(['x' => 1], ['x' => 'required']))->toThrow(\Error::class);
});
