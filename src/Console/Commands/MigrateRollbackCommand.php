<?php

declare(strict_types=1);

namespace Marrow\Console\Commands;

use Marrow\Console\Command;
use Marrow\Database\Connection;
use Marrow\Database\Migrations\Migrator;
use Marrow\Module\ModuleManager;

/**
 * Rolls back the most recently run batch of migrations.
 */
class MigrateRollbackCommand extends Command
{
    protected string $signature = 'migrate:rollback {--path=}';
    protected string $description = 'Rollback the last batch of migrations';

    public function __construct(
        private readonly Connection $db,
        private readonly ModuleManager $modules
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        $migrator   = new Migrator($this->db);
        $explicitPath = $this->option('path');
        // See MigrateCommand::resolveMigrationPaths() — module paths come
        // from the manager (config + auto-discovered), not raw config().
        $paths      = $explicitPath !== null && is_dir((string) $explicitPath)
            ? [(string) $explicitPath]
            : Migrator::discoverPaths(base_path(), array_keys($this->modules->getModulePaths()));

        $rolledBack = [];
        foreach ($paths as $p) {
            $rolledBack = array_merge($rolledBack, $migrator->rollback($p));
        }

        if (empty($rolledBack)) {
            $this->info('Nothing to rollback.');
        } else {
            $this->newLine();
            foreach ($rolledBack as $item) {
                $this->migrationLine($item['file'], $item['ms'], rollback: true);
            }
            $this->newLine();
        }

        return self::SUCCESS;
    }
}
