<?php

declare(strict_types=1);

namespace Marrow\Console\Commands;

use Marrow\Console\Command;

/**
 * Scaffolds a new Notification class.
 */
class MakeNotificationCommand extends Command
{
    protected string $signature   = 'make:notification {name?} {--module=}';
    protected string $description  = 'Create a new notification class';

    protected function handle(): int
    {
        $name   = $this->validClassName($this->argumentOrAsk('name', 'Notification name (e.g. InvoicePaid):'));
        $module = $this->moduleOption();

        if ($module) {
            $path = base_path("modules/{$module}/Notifications/{$name}.php");
            $ns   = "Modules\\{$module}\\Notifications";
        } else {
            $path = base_path("app/Notifications/{$name}.php");
            $ns   = 'App\\Notifications';
        }

        @mkdir(dirname($path), 0755, true);

        file_put_contents($path, <<<PHP
<?php

declare(strict_types=1);

namespace {$ns};

use Marrow\\Notifications\\Notification;

class {$name} extends Notification
{
    public function via(object \$notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object \$notifiable, \\Marrow\\Mail\\Mailer \$mailer): \\Marrow\\Mail\\PendingMail
    {
        return \$mailer->to(\$notifiable->email)
            ->subject('Notification')
            ->html('<p>Hello!</p>');
    }

    public function toDatabase(object \$notifiable): array
    {
        return [
            // ...
        ];
    }
}
PHP);
        $this->success("Notification [{$name}] created.");
        return self::SUCCESS;
    }
}
