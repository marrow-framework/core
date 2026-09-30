<?php

declare(strict_types=1);

namespace Marrow\Console\Commands;

use Marrow\Console\Command;

/**
 * Generate a new Policy class.
 *
 *   php forge make:policy PostPolicy --model=Post --module=Blog
 *
 * Creates:  skeleton/modules/Blog/Policies/PostPolicy.php
 */
class MakePolicyCommand extends Command
{
    protected string $signature   = 'make:policy {name : Policy class name (e.g. PostPolicy)} {--model= : Model class this policy guards} {--module= : Module to create the policy in}';
    protected string $description = 'Generate a new authorization Policy class';

    protected function handle(): int
    {
        $name   = $this->validClassName((string) $this->argument('name'));
        $model  = (string) ($this->option('model') ?? '');
        $module = $this->moduleOption();

        $className = str_ends_with($name, 'Policy') ? $name : $name . 'Policy';

        if ($module !== null) {
            $namespace = "Modules\\{$module}\\Policies";
            $dir       = base_path("modules/{$module}/Policies");
        } else {
            $namespace = 'App\\Policies';
            $dir       = base_path('app/Policies');
        }

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->error("Could not create directory: {$dir}");
            return 1;
        }

        $file = $dir . '/' . $className . '.php';

        if (file_exists($file)) {
            $this->warn("Policy already exists: {$file}");
            return 0;
        }

        file_put_contents($file, $this->buildStub($namespace, $className, $model));

        $rel = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file);
        $this->success("Policy created: <options=bold>{$rel}</>");

        if ($model !== '') {
            $modelBase = basename(str_replace('\\', '/', $model));
            $this->info("Register it: Gate::policy({$modelBase}::class, {$className}::class)");
        }

        if ($module !== null) {
            $this->registerAsProvider($module, "{$namespace}\\{$className}");
        }

        return 0;
    }

    private function buildStub(string $namespace, string $className, string $model): string
    {
        $modelImport = $model !== '' ? "\nuse {$model};\n" : '';
        $modelBase   = $model !== '' ? basename(str_replace('\\', '/', $model)) : 'Model';
        $modelParam  = $model !== '' ? ", {$modelBase} \$" . lcfirst($modelBase) : '';

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Marrow\Auth\Policy;
{$modelImport}
class {$className} extends Policy
{
    /**
     * Called before every ability — return true/false to short-circuit,
     * return null to continue to the specific ability method.
     */
    public function before(object \$user, string \$ability): ?bool
    {
        // Super-admin bypass example:
        // if (method_exists(\$user, 'hasRole') && \$user->hasRole('admin')) {
        //     return true;
        // }
        return null;
    }

    public function view(object \$user{$modelParam}): bool
    {
        return true;
    }

    public function create(object \$user): bool
    {
        return true;
    }

    public function update(object \$user{$modelParam}): bool
    {
        return true;
    }

    public function delete(object \$user{$modelParam}): bool
    {
        return true;
    }
}
PHP;
    }

}
