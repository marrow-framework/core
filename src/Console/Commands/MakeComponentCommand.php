<?php

declare(strict_types=1);

namespace Marrow\Console\Commands;

use Marrow\Console\Command;

/**
 * Scaffolds a view Component class alongside its Twig template.
 */
class MakeComponentCommand extends Command
{
    protected string $signature = 'make:component {name? : Component name in PascalCase (e.g. Alert)} {--module= : Target module}';
    protected string $description = 'Create a new view Component class and its Twig template';

    public function handle(): int
    {
        $name = $this->validClassName($this->argumentOrAsk('name', 'Component name (PascalCase, e.g. Alert):'));
        $module = (string) ($this->moduleOption() ?? '');
        $slug = $this->toKebab($name);

        [$phpNs, $phpDir, $viewDir] = $this->resolvePaths($name, $module);

        $phpFile = $phpDir . '/' . $name . 'Component.php';
        $tplFile = $viewDir . '/' . $slug . '.html.twig';

        foreach ([$phpDir, $viewDir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        if (!is_file($phpFile)) {
            file_put_contents($phpFile, $this->phpStub($phpNs, $name, $slug, $module));
            $this->success("Component class  : {$phpFile}");
        } else {
            $this->warn("Already exists   : {$phpFile}");
        }

        if (!is_file($tplFile)) {
            file_put_contents($tplFile, $this->twigStub($slug));
            $this->success("Component template: {$tplFile}");
        } else {
            $this->warn("Already exists   : {$tplFile}");
        }

        return 0;
    }

    private function resolvePaths(string $name, string $module): array
    {
        if ($module) {
            $ns = "Modules\\{$module}\\View\\Components";
            $phpDir = base_path("modules/{$module}/View/Components");
            $viewDir = base_path("modules/{$module}/Views/components");
        } else {
            $ns = 'App\\View\\Components';
            $phpDir = base_path('app/View/Components');
            $viewDir = base_path('resources/views/components');
        }

        return [$ns, $phpDir, $viewDir];
    }

    private function toKebab(string $name): string
    {
        return strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1-$2', $name));
    }

    private function phpStub(string $namespace, string $name, string $slug, string $module): string
    {
        $template = $module ? "@{$this->toKebab($module)}/components/{$slug}" : "components/{$slug}";

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Marrow\Template\Component;

class {$name}Component extends Component
{
    // Public properties become variables in the template
    public string \$type    = 'info';
    public string \$message = '';

    public function render(): string
    {
        return '{$template}';
    }
}
PHP;
    }

    private function twigStub(string $slug): string
    {
        return <<<TWIG
{# Component: {$slug} #}
<div class="component component-{$slug} {{ type }}">
    {{ message }}
</div>
TWIG;
    }
}
