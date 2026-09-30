<?php

declare(strict_types=1);

namespace Marrow\Console\Commands;

use Marrow\Console\Command;

/**
 * Scaffolds a new FormRequest class for validated controller input.
 */
class MakeFormRequestCommand extends Command
{
    protected string $signature = 'make:form-request {name? : Class name (e.g. StorePostRequest)} {--module= : Target module}';
    protected string $description = 'Create a new FormRequest class';

    public function handle(): int
    {
        $name = $this->validClassName($this->argumentOrAsk('name', 'FormRequest name (e.g. StorePostRequest):'));
        $module = $this->moduleOption();

        [$namespace, $dir] = $this->resolveTarget($name, (string) $module);
        $class = class_basename($name);
        $file = $dir . '/' . $class . '.php';

        if (is_file($file)) {
            $this->error("File already exists: {$file}");
            return 1;
        }

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($file, $this->stub($namespace, $class));
        $this->success("FormRequest created: {$file}");
        return 0;
    }

    private function resolveTarget(string $name, string $module): array
    {
        $base = base_path('modules');

        if ($module) {
            $ns = "Modules\\{$module}\\Http\\Requests";
            $dir = "{$base}/{$module}/Http/Requests";
        } else {
            $ns = 'App\\Http\\Requests';
            $dir = base_path('app/Http/Requests');
        }

        $parts = explode('/', str_replace('\\', '/', $name));
        array_pop($parts); // remove class name

        if ($parts) {
            $sub = implode('/', $parts);
            $ns .= '\\' . str_replace('/', '\\', $sub);
            $dir .= '/' . $sub;
        }

        return [$ns, $dir];
    }

    private function stub(string $namespace, string $class): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Marrow\Http\FormRequest;

class {$class} extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 'field' => 'required|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [];
    }
}
PHP;
    }
}
