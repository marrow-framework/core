<?php

declare(strict_types=1);

namespace Marrow\Console;

use Marrow\Container;
use Marrow\Module\ModuleManager;
use Symfony\Component\Console\Application as ConsoleApp;

/**
 * Console Kernel. Registers all framework and application commands,
 * then hands off to Symfony Console.
 */
class Kernel
{
    private ConsoleApp $console;

    public function __construct(
        private readonly Container $container,
        string $name = 'Marrow',
        string $version = '0.1.0'
    ) {
        $this->console = new ConsoleApp($name, $version);
        $this->registerFrameworkCommands();
    }

    public function handle(): int
    {
        // Register module commands after boot
        try {
            $manager = $this->container->make(ModuleManager::class);
            foreach ($manager->getAllCommands() as $commandClass) {
                $this->console->addCommand($this->container->make($commandClass));
            }
        } catch (\Throwable) {
        }

        return $this->console->run();
    }

    public function add(Command $command): void
    {
        $this->console->addCommand($command);
    }

    private function registerFrameworkCommands(): void
    {
        $commands = [
            Commands\ServeCommand::class,
            Commands\MakeModuleCommand::class,
            Commands\MakeControllerCommand::class,
            Commands\MakeModelCommand::class,
            Commands\MakeMiddlewareCommand::class,
            Commands\MakeCommandCommand::class,
            Commands\MakeMigrationCommand::class,
            Commands\MakeServiceCommand::class,
            Commands\MakeEventCommand::class,
            Commands\MakeListenerCommand::class,
            Commands\MakeSeederCommand::class,
            Commands\MakeFactoryCommand::class,
            Commands\MakeFormRequestCommand::class,
            Commands\MakeResourceCommand::class,
            Commands\MakeComponentCommand::class,
            Commands\RouteListCommand::class,
            Commands\ModuleGraphCommand::class,
            Commands\MigrateCommand::class,
            Commands\MigrateRollbackCommand::class,
            Commands\MigrateFreshCommand::class,
            Commands\MigrateStatusCommand::class,
            Commands\DbSeedCommand::class,
            Commands\CacheClearCommand::class,
            Commands\TwigLintCommand::class,
            Commands\KeyGenerateCommand::class,
            Commands\DownCommand::class,
            Commands\UpCommand::class,
            Commands\AboutCommand::class,
            Commands\TinkerCommand::class,
            Commands\MakePolicyCommand::class,
            Commands\MakeJobCommand::class,
            Commands\MakeNotificationCommand::class,
            Commands\MakeMailCommand::class,
            Commands\MakeTestCommand::class,
            Commands\QueueWorkCommand::class,
            Commands\ScheduleRunCommand::class,
        ];

        foreach ($commands as $class) {
            try {
                $cmd = $this->container->make($class);
                $this->console->addCommand($cmd);
            } catch (\Throwable $e) {
                // Skip commands that can't be instantiated (missing deps)
            }
        }
    }
}
