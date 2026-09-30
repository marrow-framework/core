<?php

declare(strict_types=1);

namespace Marrow\Console\Commands;

use Marrow\Console\Command;
use Marrow\Scheduling\Schedule;

/**
 * Runs all scheduled tasks that are due. Wire this to a single system cron:
 *
 *   * * * * * cd /path/to/app && php forge schedule:run >> /dev/null 2>&1
 */
class ScheduleRunCommand extends Command
{
    protected string $signature   = 'schedule:run';
    protected string $description = 'Run scheduled tasks that are due';

    public function __construct(private readonly Schedule $schedule)
    {
        parent::__construct();
    }

    protected function handle(): int
    {
        $results = $this->schedule->run();

        if (empty($results)) {
            $this->info('No scheduled tasks are due.');
            return self::SUCCESS;
        }

        foreach ($results as $result) {
            if (str_starts_with($result['status'], 'error')) {
                $this->error("{$result['event']} — {$result['status']}");
            } else {
                $this->success($result['event']);
            }
        }

        return self::SUCCESS;
    }
}
