<?php

declare(strict_types=1);

namespace Marrow\Console\Commands;

use Marrow\Console\Command;
use Marrow\Container;
use Marrow\Database\Connection;
use Marrow\Module\ModuleManager;

/**
 * Display framework and environment information.
 */
class AboutCommand extends Command
{
    protected string $signature   = 'about';
    protected string $description = 'Display information about the Marrow application';

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function handle(): int
    {
        $version = $_ENV['APP_VERSION'] ?? '0.2.0';

        $this->newLine();
        $this->output->writeln("   <options=bold;fg=blue>INFO</>  Marrow <options=bold>v{$version}</> — Application Information");
        $this->newLine();

        // ── Environment ──────────────────────────────────────────────
        $this->output->writeln('   <options=bold>Environment</>');
        $this->twoColumnDetail('   PHP',       PHP_VERSION);
        $this->twoColumnDetail('   Framework', $version);
        $this->twoColumnDetail('   App',       $_ENV['APP_NAME'] ?? 'Marrow');
        $this->twoColumnDetail('   Env',       $_ENV['APP_ENV'] ?? 'production');
        $debug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
        $this->twoColumnDetail('   Debug', $debug ? '<fg=yellow>enabled</>' : '<fg=gray>disabled</>');
        $this->twoColumnDetail('   Path',      base_path());
        $this->newLine();

        // ── Cache ────────────────────────────────────────────────────
        $this->output->writeln('   <options=bold>Cache</>');
        $cacheDriver = function_exists('apcu_fetch') ? 'APCu' : 'File';
        $this->twoColumnDetail('   Driver', $cacheDriver);
        $this->newLine();

        // ── Database ─────────────────────────────────────────────────
        $this->output->writeln('   <options=bold>Database</>');
        try {
            /** @var Connection $db */
            $db  = $this->container->make(Connection::class);
            $cls = get_class($db->getPlatform());
            $platform = substr($cls, (int) strrpos($cls, '\\') + 1);
            $this->twoColumnDetail('   Driver', $platform);
        } catch (\Throwable) {
            $this->twoColumnDetail('   Driver', '<fg=gray>not connected</>');
        }
        $this->newLine();

        // ── Modules ──────────────────────────────────────────────────
        $this->output->writeln('   <options=bold>Modules</>');
        try {
            /** @var ModuleManager $manager */
            $manager = $this->container->make(ModuleManager::class);
            $modules = $manager->getLoadedModules();
            if (empty($modules)) {
                $this->output->writeln('   <fg=gray>(none)</>');
            } else {
                foreach ($modules as $name => $module) {
                    $this->twoColumnDetail("   {$name}", '<fg=green>loaded</>');
                }
            }
        } catch (\Throwable) {
            $this->output->writeln('   <fg=gray>(module system not available)</>');
        }
        $this->newLine();

        return self::SUCCESS;
    }
}
