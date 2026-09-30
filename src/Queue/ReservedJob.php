<?php

declare(strict_types=1);

namespace Marrow\Queue;

/**
 * A job that has been reserved off the queue and is ready to run.
 * Carries the row id and current attempt count alongside the job instance.
 */
final class ReservedJob
{
    public function __construct(
        public readonly int $id,
        public readonly Job $job,
        public readonly int $attempts
    ) {
    }
}
