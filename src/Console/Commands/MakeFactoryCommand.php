<?php

declare(strict_types=1);

namespace Marrow\Console\Commands;

use Marrow\Console\Command;

/**
 * Scaffolds a new model Factory class.
 */
class MakeFactoryCommand extends Command
{
    protected string $signature = 'make:factory {name?} {--module=}';
    protected string $description = 'Create a new model factory class';

    protected function handle(): int
    {
        $name = $this->validClassName($this->argumentOrAsk('name', 'Factory name (e.g. PostFactory):'));
        $module = $this->moduleOption();

        $path = $module
            ? base_path("modules/{$module}/Database/Factories/{$name}.php")
            : base_path("database/factories/{$name}.php");
        $ns = $module ? "Modules\\{$module}\\Database\\Factories" : "Database\\Factories";

        @mkdir(dirname($path), 0755, true);

        file_put_contents($path, <<<PHP
<?php

declare(strict_types=1);

namespace {$ns};

use Marrow\\Database\\Factory;

class {$name} extends Factory
{
    public function definition(): array
    {
        \$fake = \$this->fake();

        return [
            // 'name'       => \$fake->name(),
            // 'email'      => \$fake->email(),
            // 'title'      => \$fake->sentence(),
            // 'body'       => \$fake->paragraph(),
            // 'published'  => \$fake->boolean(),
            // 'created_at' => \$fake->dateTimeBetween('-1 year'),
        ];
    }
}
PHP);
        $this->success("Factory [{$name}] created.");
        return self::SUCCESS;
    }
}
