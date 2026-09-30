<?php

declare(strict_types=1);

use Marrow\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
| All tests in tests/Unit/ use the Marrow TestCase by default.
| Integration tests that need the full Application stack can apply the
| RefreshDatabase trait via `uses(RefreshDatabase::class)` in the file.
*/
uses(TestCase::class)->in('Unit');
