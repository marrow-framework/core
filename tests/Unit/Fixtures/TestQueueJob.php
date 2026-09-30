<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit\Fixtures;

use Marrow\Queue\Job;

class TestQueueJob extends Job
{
    public function __construct(public string $note = 'hello')
    {
    }

    public function handle(): void
    {
    }
}
