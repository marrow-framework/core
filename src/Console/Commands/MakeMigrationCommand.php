<?php

declare(strict_types=1);

namespace Marrow\Console\Commands;

use Marrow\Console\Command;

/**
 * Scaffolds a new timestamped migration file.
 */
class MakeMigrationCommand extends Command
{
    protected string $signature = 'make:migration {name?} {--module=}';
    protected string $description = 'Create a new migration file';

    /** @throws \InvalidArgumentException If the migration name contains a slash or '..'. */
    protected function handle(): int
    {
        $rawName = $this->argumentOrAsk('name', 'Migration name (e.g. create_posts_table):');
        if (preg_match('#[\\\\/]|\.\.#', $rawName)) {
            throw new \InvalidArgumentException("Invalid migration name [{$rawName}] — must not contain slashes or '..'.");
        }
        $name = str_replace(' ', '_', strtolower($rawName));
        $module = $this->moduleOption();
        $stamp = date('Y_m_d_His');
        $file = "{$stamp}_{$name}.php";

        $path = $module
            ? base_path("modules/{$module}/Database/Migrations/{$file}")
            : base_path("database/migrations/{$file}");

        @mkdir(dirname($path), 0755, true);

        $class = implode('', array_map('ucfirst', explode('_', $name)));
        $table = preg_replace('/^(create|add|drop)_(.+?)(_table)?$/', '$2', $name);

        file_put_contents($path, <<<PHP
<?php

declare(strict_types=1);

use Marrow\\Database\\Migrations\\Migration;
use Marrow\\Database\\Schema\\Schema;
use Marrow\\Database\\Schema\\Table;

class {$class} extends Migration
{
    public function up(): void
    {
        Schema::create('{$table}', function (Table \$t) {
            \$t->id();
            \$t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::drop('{$table}');
    }
}
PHP);
        $this->success("Migration [{$file}] created.");
        return self::SUCCESS;
    }
}
