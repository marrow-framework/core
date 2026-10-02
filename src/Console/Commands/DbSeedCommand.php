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

        try {
            $seeder = $this->container->make($class);
            $seeder->run();
        } catch (\Throwable $e) {
            // fakerphp/faker is a dev-only dependency (Database\Factory::fake())
            // — unlike `tinker`'s psy/psysh, there's no earlier point to catch
            // this at (the seeder itself is only resolvable/runnable here), so
            // a missing-class \Error from it needs its own friendly message
            // rather than surfacing as a raw stack trace.
            if (str_contains($e->getMessage(), 'Faker\\')) {
                $this->error(
                    "Seeder [{$class}] uses fake data (Database\\Factory::fake()), " .
                    "but fakerphp/faker isn't installed — it's a dev-only dependency. " .
                    "Run: composer require --dev fakerphp/faker"
                );
                return self::FAILURE;
            }

            $this->error("Seeder [{$class}] failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        $this->success("Seeded [{$class}]");
        return self::SUCCESS;
    }
}
