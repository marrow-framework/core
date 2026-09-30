<?php

declare(strict_types=1);

namespace Marrow\Console\Commands;

use Marrow\Console\Command;

/**
 * Scaffolds a new console command class extending Command.
 */
class MakeCommandCommand extends Command
{
    protected string $signature = 'make:command {name?} {--module=}';
    protected string $description = 'Create a new console command class';

    protected function handle(): int
    {
        $name = $this->validClassName($this->argumentOrAsk('name', 'Command class name (e.g. SendReportCommand):'));
        $module = $this->moduleOption();

        if ($module) {
            $path = base_path("modules/{$module}/Commands/{$name}.php");
            $ns = "Modules\\{$module}\\Commands";
        } else {
            $path = base_path("app/Commands/{$name}.php");
            $ns = "App\\Commands";
            @mkdir(dirname($path), 0755, true);
        }

        @mkdir(dirname($path), 0755, true);

        if (is_file($path)) {
            $this->error("Command [{$name}] already exists.");
            return self::FAILURE;
        }

        $sig = strtolower(preg_replace('/([a-z])([A-Z])/', '$1:$2', str_replace('Command', '', $name)));

        file_put_contents($path, <<<PHP
<?php

declare(strict_types=1);

namespace {$ns};

use Marrow\\Console\\Command;

class {$name} extends Command
{
    protected string \$signature   = '{$sig}';
    protected string \$description = '';

    protected function handle(): int
    {
        \$this->info('Running {$name}...');
        return self::SUCCESS;
    }
}
PHP);
        $this->success("Command [{$name}] created.");
        return self::SUCCESS;
    }
}
