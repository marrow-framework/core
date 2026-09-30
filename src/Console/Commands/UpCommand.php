<?php

declare(strict_types=1);

namespace Marrow\Console\Commands;

use Marrow\Console\Command;

/**
 * Takes the application out of maintenance mode by removing the
 * storage/maintenance.flag file written by `down`.
 */
class UpCommand extends Command
{
    protected string $signature = 'up';
    protected string $description = 'Bring the application out of maintenance mode';

    protected function handle(): int
    {
        $flag = base_path('storage/maintenance.flag');
        if (is_file($flag)) {
            unlink($flag);
            $this->success('Application is now live.');
        } else {
            $this->info('Application is not in maintenance mode.');
        }
        return self::SUCCESS;
    }
}
