<?php

declare(strict_types=1);

namespace Marrow\Console\Commands;

use Marrow\Console\Command;

/**
 * Scaffolds a new #[Injectable] service class and, when generated inside a
 * module (`--module`), registers it in that module's `providers:`.
 */
class MakeServiceCommand extends Command
{
    protected string $signature = 'make:service {name?} {--module=}';
    protected string $description = 'Create a new service class';

    protected function handle(): int
    {
        $name = $this->validClassName($this->argumentOrAsk('name', 'Service name (e.g. PostService):'));
        $module = $this->moduleOption();

        if ($module) {
            $path = base_path("modules/{$module}/Services/{$name}.php");
            $ns = "Modules\\{$module}\\Services";
        } else {
            $path = base_path("app/Services/{$name}.php");
            $ns = "App\\Services";
            @mkdir(dirname($path), 0755, true);
        }

        @mkdir(dirname($path), 0755, true);

        file_put_contents($path, <<<PHP
<?php

declare(strict_types=1);

namespace {$ns};

use Marrow\\Attributes\\Injectable;

#[Injectable]
class {$name}
{
    //
}
PHP);
        $this->success("Service [{$name}] created.");

        if ($module) {
            $this->registerAsProvider($module, "{$ns}\\{$name}");
        }

        return self::SUCCESS;
    }
}
