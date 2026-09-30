<?php

declare(strict_types=1);

namespace Marrow\Console\Commands;

use Marrow\Console\Command;

/**
 * Scaffolds a new event listener class and, when generated inside a module
 * (`--module`), registers it in that module's `providers:`.
 */
class MakeListenerCommand extends Command
{
    protected string $signature = 'make:listener {name?} {--event=} {--module=}';
    protected string $description = 'Create a new event listener class';

    protected function handle(): int
    {
        $name = $this->validClassName($this->argumentOrAsk('name', 'Listener name (e.g. SendWelcomeEmail):'));
        $rawEvent = $this->option('event');
        $event = $this->validClassName(is_string($rawEvent) && $rawEvent !== '' ? $rawEvent : 'SomeEvent');
        $module = $this->moduleOption();

        $path = $module
            ? base_path("modules/{$module}/Listeners/{$name}.php")
            : base_path("app/Listeners/{$name}.php");
        $ns = $module ? "Modules\\{$module}\\Listeners" : "App\\Listeners";

        @mkdir(dirname($path), 0755, true);

        file_put_contents($path, <<<PHP
<?php

declare(strict_types=1);

namespace {$ns};

class {$name}
{
    public function handle({$event} \$event): void
    {
        //
    }
}
PHP);
        $this->success("Listener [{$name}] created.");

        if ($module) {
            $this->registerAsProvider($module, "{$ns}\\{$name}");
        }

        return self::SUCCESS;
    }
}
