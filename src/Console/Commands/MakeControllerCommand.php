<?php

declare(strict_types=1);

namespace Marrow\Console\Commands;

use Marrow\Console\Command;

/**
 * Scaffolds a new controller class — plain, resourceful (`--resource`), or
 * API-only (`--api`) — and registers it in the target module's
 * `providers:` when `--module` is used.
 */
class MakeControllerCommand extends Command
{
    protected string $signature = 'make:controller {name?} {--module=} {--resource} {--api}';
    protected string $description = 'Create a new controller class';

    protected function handle(): int
    {
        $name = $this->validClassName($this->argumentOrAsk('name', 'Controller name (e.g. PostController):'));
        $module = $this->moduleOption();
        $resource = (bool) $this->option('resource');
        $api = (bool) $this->option('api');

        if ($module) {
            $path = base_path("modules/{$module}/Controllers/{$name}.php");
            $ns = "Modules\\{$module}\\Controllers";
        } else {
            $path = base_path("app/Controllers/{$name}.php");
            $ns = "App\\Controllers";
            @mkdir(base_path('app/Controllers'), 0755, true);
        }

        if (is_file($path)) {
            $this->error("Controller [{$name}] already exists.");
            return self::FAILURE;
        }

        $methods = $resource ? $this->resourceMethods() : $this->basicMethods();
        $content = $this->stub($ns, $name, $methods, $api);

        file_put_contents($path, $content);
        $this->success("Controller [{$name}] created.");

        if ($module) {
            $this->registerAsProvider($module, "{$ns}\\{$name}");
        }

        return self::SUCCESS;
    }

    private function stub(string $ns, string $name, string $methods, bool $api): string
    {
        $base    = $api ? 'ApiController' : 'Controller';
        $useBase = "use Marrow\\Http\\{$base};";

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$ns};

use Marrow\\Http\\Request;
use Marrow\\Http\\Response;
{$useBase}

class {$name} extends {$base}
{
{$methods}
}
PHP;
    }

    private function basicMethods(): string
    {
        return <<<PHP
    public function index(Request \$request): Response
    {
        return Response::view('index', []);
    }
PHP;
    }

    private function resourceMethods(): string
    {
        return <<<'PHP'
    public function index(Request $request): Response
    {
        return Response::view('index', []);
    }

    public function create(Request $request): Response
    {
        return Response::view('create', []);
    }

    public function store(Request $request): Response
    {
        $data = $request->validate([]);
        // store...
        return Response::redirect('/')->route('index');
    }

    public function show(Request $request, int $id): Response
    {
        return Response::view('show', ['id' => $id]);
    }

    public function edit(Request $request, int $id): Response
    {
        return Response::view('edit', ['id' => $id]);
    }

    public function update(Request $request, int $id): Response
    {
        $data = $request->validate([]);
        // update...
        return Response::redirect('/')->route('index');
    }

    public function destroy(Request $request, int $id): Response
    {
        // delete...
        return Response::redirect('/')->route('index');
    }
PHP;
    }
}
