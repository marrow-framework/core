<?php

declare(strict_types=1);

namespace Marrow\Console\Commands;

use Marrow\Console\Command;

/**
 * Writes storage/maintenance.flag containing a bypass secret; MaintenanceMode
 * middleware then returns 503 for every request except one carrying that
 * secret in a `maintenance_bypass` cookie.
 */
class DownCommand extends Command
{
    protected string $signature   = 'down {--secret=}';
    protected string $description = 'Put the application into maintenance mode';

    protected function handle(): int
    {
        $secret = $this->option('secret') ?? bin2hex(random_bytes(8));
        $flag   = base_path('storage/maintenance.flag');
        file_put_contents($flag, $secret);

        $this->warn('Application is now in maintenance mode.');
        $this->output->writeln("   <fg=gray>→</>  Bypass secret: <options=bold>{$secret}</>");

        return self::SUCCESS;
    }
}
