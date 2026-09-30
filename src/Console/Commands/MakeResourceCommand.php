<?php

declare(strict_types=1);

namespace Marrow\Console\Commands;

use Marrow\Console\Command;

/**
 * Scaffolds a new API Resource class (or a ResourceCollection with
 * `--collection`).
 */
class MakeResourceCommand extends Command
{
    protected string $signature = 'make:resource {name? : Class name (e.g. PostResource)} {--collection : Generate a ResourceCollection instead} {--module= : Target module}';
    protected string $description = 'Create a new API Resource class';

    public function handle(): int
    {
        $name = $this->validClassName($this->argumentOrAsk('name', 'Resource name (e.g. PostResource):'));
        $collection = (bool) $this->option('collection');
        $module = (string) ($this->moduleOption() ?? '');

        [$namespace, $dir] = $this->resolveTarget($name, $module);
        $class = class_basename($name);
        $file = $dir . '/' . $class . '.php';

        if (is_file($file)) {
            $this->error("File already exists: {$file}");
            return 1;
        }

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $stub = $collection
            ? $this->collectionStub($namespace, $class)
            : $this->resourceStub($namespace, $class);

        file_put_contents($file, $stub);
        $this->success("Resource created: {$file}");
        return 0;
    }

    private function resolveTarget(string $name, string $module): array
    {
        $base = base_path('modules');

        if ($module) {
            $ns = "Modules\\{$module}\\Http\\Resources";
            $dir = "{$base}/{$module}/Http/Resources";
        } else {
            $ns = 'App\\Http\\Resources';
            $dir = base_path('app/Http/Resources');
        }

        $parts = explode('/', str_replace('\\', '/', $name));
        array_pop($parts);

        if ($parts) {
            $sub = implode('/', $parts);
            $ns .= '\\' . str_replace('/', '\\', $sub);
            $dir .= '/' . $sub;
        }

        return [$ns, $dir];
    }

    private function resourceStub(string $namespace, string $class): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Marrow\Http\Request;
use Marrow\Http\Resources\JsonResource;

class {$class} extends JsonResource
{
    public function toArray(Request \$request): array
    {
        return [
            'id'         => \$this->id,
            'created_at' => \$this->created_at,
        ];
    }
}
PHP;
    }

    private function collectionStub(string $namespace, string $class): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Marrow\Http\Request;
use Marrow\Http\Resources\JsonResource;

class {$class} extends JsonResource
{
    public function toArray(Request \$request): array
    {
        return \$this->resource;
    }
}
PHP;
    }
}
