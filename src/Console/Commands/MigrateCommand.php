<?php

declare(strict_types=1);

namespace Marrow\Console\Commands;

use Marrow\Console\Command;
use Marrow\Database\Connection;
use Marrow\Database\Migrations\Migrator;
use Marrow\Module\ModuleManager;

/**
 * Runs all pending migrations. `--fresh` drops every table first
 * (destructive — asks for confirmation unless `--force` is also passed);
 * `--seed` runs seeders afterwards; `--rollback` rolls back instead.
 */
class MigrateCommand extends Command
{
    protected string $signature   = 'migrate {--path=} {--fresh} {--seed} {--rollback} {--force}';
    protected string $description = 'Run pending database migrations';

    public function __construct(
        private readonly Connection $db,
        private readonly ModuleManager $modules
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        $migrator = new Migrator($this->db);

        if ($this->option('rollback')) {
            return $this->runRollback($migrator);
        }

        $explicitPath = $this->option('path');

        if ($explicitPath !== null) {
            return $this->runPath($migrator, (string) $explicitPath);
        }

        return $this->runAll($migrator);
    }

    private function runAll(Migrator $migrator): int
    {
        if ($this->option('fresh') && !$this->freshAll($migrator)) {
            return self::SUCCESS;
        }

        $paths   = $this->resolveMigrationPaths();
        $any     = false;

        foreach ($paths as $path) {
            $ran = $migrator->run($path);
            if (!empty($ran)) {
                if (!$any) {
                    $this->newLine();
                    $any = true;
                }
                foreach ($ran as $item) {
                    $this->migrationLine($item['file'], $item['ms']);
                }
            }
        }

        if (!$any) {
            $this->info('Nothing to migrate.');
        } else {
            $this->newLine();
        }

        if ($this->option('seed')) {
            $this->call('db:seed');
        }

        return self::SUCCESS;
    }

    private function runPath(Migrator $migrator, string $path): int
    {
        if (!is_dir($path)) {
            $this->error("Migration path [{$path}] does not exist.");
            return self::FAILURE;
        }

        $ran = $migrator->run($path);

        if (empty($ran)) {
            $this->info('Nothing to migrate.');
        } else {
            $this->newLine();
            foreach ($ran as $item) {
                $this->migrationLine($item['file'], $item['ms']);
            }
            $this->newLine();
        }

        return self::SUCCESS;
    }

    private function runRollback(Migrator $migrator): int
    {
        $paths = $this->resolveMigrationPaths();
        $any   = false;

        foreach ($paths as $path) {
            $rolled = $migrator->rollback($path);
            if (!empty($rolled)) {
                if (!$any) {
                    $this->newLine();
                    $any = true;
                }
                foreach ($rolled as $item) {
                    $this->output->writeln(
                        "  <fg=yellow>ROLLBACK</>  {$item['file']} <fg=gray>({$item['ms']}ms)</>"
                    );
                }
            }
        }

        if (!$any) {
            $this->info('Nothing to roll back.');
        } else {
            $this->newLine();
        }

        return self::SUCCESS;
    }

    /** @return bool True if the drop proceeded; false if the user declined confirmation. */
    private function freshAll(Migrator $migrator): bool
    {
        if (!$this->option('force') && !$this->confirm('This will drop all tables. Are you sure?', false)) {
            return false;
        }

        $this->warn('Dropping all tables and re-running all migrations...');
        foreach ($this->resolveMigrationPaths() as $path) {
            $migrator->fresh($path);
        }

        return true;
    }

    /**
     * @return string[]
     *
     * Uses ModuleManager::getModulePaths() rather than
     * config('modules.enabled') directly: by the time a console command
     * runs, Application::boot() has already merged the config file's list
     * with any package-auto-discovered modules (see PackageDiscovery) into
     * the manager — reading the raw config key here would silently miss
     * every auto-discovered module's migrations.
     */
    private function resolveMigrationPaths(): array
    {
        return Migrator::discoverPaths(base_path(), array_keys($this->modules->getModulePaths()));
    }
}
