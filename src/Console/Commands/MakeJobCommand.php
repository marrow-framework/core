<?php

declare(strict_types=1);

namespace Marrow\Console\Commands;

use Marrow\Console\Command;

/**
 * Scaffolds a new queue Job class.
 */
class MakeJobCommand extends Command
{
    protected string $signature   = 'make:job {name?} {--module=}';
    protected string $description  = 'Create a new queued job class';

    protected function handle(): int
    {
        $name   = $this->validClassName($this->argumentOrAsk('name', 'Job name (e.g. SendWelcomeEmail):'));
        $module = $this->moduleOption();

        if ($module) {
            $path = base_path("modules/{$module}/Jobs/{$name}.php");
            $ns   = "Modules\\{$module}\\Jobs";
        } else {
            $path = base_path("app/Jobs/{$name}.php");
            $ns   = 'App\\Jobs';
        }

        @mkdir(dirname($path), 0755, true);

        file_put_contents($path, <<<PHP
<?php

declare(strict_types=1);

namespace {$ns};

use Marrow\\Queue\\Job;

class {$name} extends Job
{
    public int \$tries = 3;

    public function __construct()
    {
        //
    }

    public function handle(): void
    {
        // ...
    }
}
PHP);
        $this->success("Job [{$name}] created.");
        return self::SUCCESS;
    }
}
