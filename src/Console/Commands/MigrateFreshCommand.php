<?php

declare(strict_types=1);

namespace Marrow\Console\Commands;

use Marrow\Console\Command;
use Marrow\Database\Connection;
use Marrow\Database\Migrations\Migrator;
use Marrow\Module\ModuleManager;

/**
 * Drops every table and re-runs all migrations from scratch, optionally
 * seeding afterwards (`--seed`). Destructive — asks for confirmation
 * before proceeding, unless `--force` is given (required for non-interactive
 * use — CI, deploy scripts — same convention as `migrate --fresh --force`).
 */
class MigrateFreshCommand extends Command
{
    protected string $signature = 'migrate:fresh {--seed} {--force}';
    protected string $description = 'Drop all tables and re-run all migrations';

    public function __construct(
        private readonly Connection $db,
        private readonly ModuleManager $modules
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        if (!$this->option('force') && !$this->confirm('This will drop all tables. Are you sure?', false)) {
            return self::SUCCESS;
        }

        $migrator = new Migrator($this->db);
        // See MigrateCommand::resolveMigrationPaths() — module paths come
        // from the manager (config + auto-discovered), not raw config().
        $paths    = Migrator::discoverPaths(base_path(), array_keys($this->modules->getModulePaths()));

        $migrator->dropAll();

        $this->newLine();
        $any = false;

        foreach ($paths as $path) {
            foreach ($migrator->run($path) as $item) {
                $this->migrationLine($item['file'], $item['ms']);
                $any = true;
            }
        }

        if (!$any) {
            $this->info('Nothing to migrate.');
        }

        $this->newLine();

        if ($this->option('seed')) {
            $this->call('db:seed');
        }

        return self::SUCCESS;
    }
}
