<?php

declare(strict_types=1);

namespace Marrow\Console\Commands;

use Marrow\Console\Command;

/**
 * Scaffolds a new HMVC module: its directory structure, a module
 * definition class, and a starter controller.
 */
class MakeModuleCommand extends Command
{
    protected string $signature = 'make:module {name?}';
    protected string $description = 'Create a new HMVC module with its full directory structure';

    protected function handle(): int
    {
        $name = ucfirst($this->validClassName($this->argumentOrAsk('name', 'Module name (PascalCase, e.g. Blog):')));
        $base = base_path("modules/{$name}");

        if (is_dir($base)) {
            $this->error("Module [{$name}] already exists.");
            return self::FAILURE;
        }

        $dirs = [
            "{$base}/Controllers",
            "{$base}/Models",
            "{$base}/Services",
            "{$base}/Views",
            "{$base}/Commands",
            "{$base}/Events",
            "{$base}/Listeners",
            "{$base}/Database/Migrations",
            "{$base}/Database/Seeders",
            "{$base}/Database/Factories",
        ];

        foreach ($dirs as $dir) {
            mkdir($dir, 0755, true);
        }

        // Module class
        file_put_contents("{$base}/{$name}Module.php", $this->moduleStub($name));

        // routes.php
        file_put_contents("{$base}/routes.php", $this->routesStub($name));

        // Basic Twig view
        file_put_contents("{$base}/Views/index.html.twig", $this->viewStub($name));

        // Example controller
        file_put_contents("{$base}/Controllers/{$name}Controller.php", $this->controllerStub($name));

        $this->success("Module [{$name}] created at modules/{$name}");
        $this->line("Add \\Modules\\{$name}\\{$name}Module::class to config/modules.php");

        return self::SUCCESS;
    }

    private function moduleStub(string $name): string
    {
        $lower = strtolower($name);
        return <<<PHP
<?php

declare(strict_types=1);

namespace Modules\\{$name};

use Marrow\\Module\\Attributes\\Module;
use Marrow\\Module\\BaseModule;

#[Module(
    name: '{$lower}',
    imports: [],
    providers: [],
    exports: [],
    commands: [],
    listeners: [],
)]
class {$name}Module extends BaseModule
{
    public function register(): void
    {
        // Bind services into the container
    }

    public function boot(): void
    {
        // Routes are auto-loaded from routes.php
    }
}
PHP;
    }

    private function routesStub(string $name): string
    {
        $lower = strtolower($name);
        return <<<PHP
<?php

/**
 * Loaded by BaseModule::loadRoutes() with \$router already in scope.
 *
 * @var \Marrow\Routing\Router \$router
 */

use Modules\\{$name}\\Controllers\\{$name}Controller;

\$router->get('/{$lower}', [{$name}Controller::class, 'index'])->name('{$lower}.index');
PHP;
    }

    private function viewStub(string $name): string
    {
        return <<<TWIG
{% extends '@{$name}/layouts/app.html.twig' is defined ? '@{$name}/layouts/app.html.twig' : 'layouts/app.html.twig' %}

{% block title %}{$name}{% endblock %}

{% block content %}
<h1 class="text-2xl font-bold">{$name} Module</h1>
{% endblock %}
TWIG;
    }

    private function controllerStub(string $name): string
    {
        $lower = strtolower($name);
        return <<<PHP
<?php

declare(strict_types=1);

namespace Modules\\{$name}\\Controllers;

use Marrow\\Http\\Request;
use Marrow\\Http\\Response;

class {$name}Controller
{
    public function index(Request \$request): Response
    {
        return Response::view('@{$lower}/index', []);
    }
}
PHP;
    }
}
