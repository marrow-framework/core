<?php

declare(strict_types=1);

namespace Marrow\Console\Commands;

use Marrow\Console\Command;
use Marrow\Queue\ReservedJob;
use Marrow\Queue\Worker;

/**
 * Processes queued jobs.
 *
 *   php forge queue:work
 *   php forge queue:work --queue=emails --sleep=1
 *   php forge queue:work --once
 */
class QueueWorkCommand extends Command
{
    protected string $signature = 'queue:work
        {--queue=default : Queue name to process}
        {--sleep=3 : Seconds to wait when no job is available}
        {--once : Process a single job then exit}';

    protected string $description = 'Start processing jobs on the queue';

    public function __construct(private readonly Worker $worker)
    {
        parent::__construct();
    }

    protected function handle(): int
    {
        $queue = (string) $this->option('queue');
        $sleep = (int) $this->option('sleep');
        $once  = (bool) $this->option('once');

        $this->info("Processing jobs on [{$queue}]" . ($once ? ' (once)' : '') . '…');

        $this->worker->work($queue, $sleep, $once, function (string $status, ?ReservedJob $job) {
            match ($status) {
                'done'     => $this->success(($job?->job::class ?? 'job') . ' processed'),
                'released' => $this->warn(($job?->job::class ?? 'job') . ' failed — released for retry'),
                'failed'   => $this->error(($job?->job::class ?? 'job') . ' failed permanently'),
                'idle'     => $this->info('No jobs available.'),
                default    => null,
            };
        });

        return self::SUCCESS;
    }
}
