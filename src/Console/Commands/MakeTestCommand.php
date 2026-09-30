<?php

declare(strict_types=1);

namespace Marrow\Console\Commands;

use Marrow\Console\Command;

/**
 * Scaffolds a new Pest test file — a feature test by default, or a unit
 * test with `--unit`.
 */
class MakeTestCommand extends Command
{
    protected string $signature   = 'make:test {name?} {--unit : Create a unit test instead of a feature test} {--module=}';
    protected string $description  = 'Create a new Pest test file';

    protected function handle(): int
    {
        $name   = $this->validClassName($this->argumentOrAsk('name', 'Test name (e.g. PostController):'));
        $name   = str_ends_with($name, 'Test') ? $name : $name . 'Test';
        $module = $this->moduleOption();
        $unit   = (bool) $this->option('unit');

        $sub = $unit ? 'Unit' : 'Feature';

        if ($module) {
            $path = base_path("modules/{$module}/Tests/{$sub}/{$name}.php");
        } else {
            $path = base_path("tests/{$sub}/{$name}.php");
        }

        @mkdir(dirname($path), 0755, true);

        $subject = str_replace('Test', '', $name);

        file_put_contents($path, <<<PHP
<?php

declare(strict_types=1);

it('{$subject} works', function () {
    expect(true)->toBeTrue();
});
PHP);
        $this->success("Test [{$name}] created at {$path}.");
        return self::SUCCESS;
    }
}
