<?php

declare(strict_types=1);

namespace Marrow\Console\Commands;

use Marrow\Console\Command;

/**
 * Scaffolds a new Mailable class.
 */
class MakeMailCommand extends Command
{
    protected string $signature   = 'make:mail {name?} {--module=}';
    protected string $description  = 'Create a new mailable class';

    protected function handle(): int
    {
        $name   = $this->validClassName($this->argumentOrAsk('name', 'Mailable name (e.g. WelcomeMail):'));
        $module = $this->moduleOption();

        if ($module) {
            $path = base_path("modules/{$module}/Mail/{$name}.php");
            $ns   = "Modules\\{$module}\\Mail";
        } else {
            $path = base_path("app/Mail/{$name}.php");
            $ns   = 'App\\Mail';
        }

        @mkdir(dirname($path), 0755, true);

        file_put_contents($path, <<<PHP
<?php

declare(strict_types=1);

namespace {$ns};

use Marrow\\Mail\\Mailable;
use Symfony\\Component\\Mime\\Email;

class {$name} extends Mailable
{
    public function __construct()
    {
        //
    }

    public function build(array \$config = []): Email
    {
        return \$this->makeEmail()
            ->subject('Subject')
            ->html(\$this->renderView('emails/{$name}', []));
    }
}
PHP);
        $this->success("Mailable [{$name}] created.");
        return self::SUCCESS;
    }
}
