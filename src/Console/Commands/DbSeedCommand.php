<?php

declare(strict_types=1);

namespace Marrow\Console\Commands;

use Marrow\Console\Command;
use Marrow\Container;

/**
 * Resolves a Seeder class from the container (Database\Seeders\DatabaseSeeder
 * by default — matching make:seeder's own namespace convention for
 * non-module seeders — or an explicit `--class`) and runs it.
 */
class DbSeedCommand extends Command
{
    protected string $signature = 'db:seed {--class=Database\Seeders\DatabaseSeeder}';
    protected string $description = 'Run database seeders';

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function handle(): int
    {
        $class = $this->option('class') ?? 'Database\Seeders\DatabaseSeeder';

        if (!class_exists($class)) {
            $this->error("Seeder class [{$class}] not found.");
            return self::FAILURE;
        }

        $seeder = $this->container->make($class);
        $seeder->run();
        $this->success("Seeded [{$class}]");
        return self::SUCCESS;
    }
}
