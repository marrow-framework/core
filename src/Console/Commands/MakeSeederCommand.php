<?php

declare(strict_types=1);

namespace Marrow\Console\Commands;

use Marrow\Console\Command;

/**
 * Scaffolds a new Seeder class.
 */
class MakeSeederCommand extends Command
{
    protected string $signature = 'make:seeder {name?} {--module=}';
    protected string $description = 'Create a new database seeder class';

    protected function handle(): int
    {
        $name = $this->validClassName($this->argumentOrAsk('name', 'Seeder name (e.g. UserSeeder):'));
        $module = $this->moduleOption();

        $path = $module
            ? base_path("modules/{$module}/Database/Seeders/{$name}.php")
            : base_path("database/seeders/{$name}.php");
        $ns = $module ? "Modules\\{$module}\\Database\\Seeders" : "Database\\Seeders";

        @mkdir(dirname($path), 0755, true);

        file_put_contents($path, <<<PHP
<?php

declare(strict_types=1);

namespace {$ns};

use Marrow\\Database\\Seeder;

class {$name} extends Seeder
{
    public function run(): void
    {
        //
    }
}
PHP);
        $this->success("Seeder [{$name}] created.");
        return self::SUCCESS;
    }
}
