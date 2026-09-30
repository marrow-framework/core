<?php

declare(strict_types=1);

namespace Marrow\Console\Commands;

use Marrow\Application;
use Marrow\Console\Command;

/**
 * Interactive PHP REPL for Marrow.
 * Uses PsySH when available (composer require psy/psysh --dev),
 * otherwise falls back to a minimal readline eval loop.
 */
class TinkerCommand extends Command
{
    protected string $signature   = 'tinker';
    protected string $description = 'Start an interactive PHP REPL with the application booted';

    public function __construct(private readonly Application $app)
    {
        parent::__construct();
    }

    protected function handle(): int
    {
        if (class_exists(\Psy\Shell::class)) {
            return $this->runPsySh($this->app);
        }

        return $this->runFallbackRepl($this->app);
    }

    private function runPsySh(Application $app): int
    {
        $shell = new \Psy\Shell(new \Psy\Configuration());
        $shell->setScopeVariables(['app' => $app]);
        $shell->run();

        return self::SUCCESS;
    }

    private function runFallbackRepl(Application $app): int
    {
        $this->newLine();
        $this->output->writeln('   <options=bold;fg=blue>INFO</>  <options=bold>Marrow Tinker</> — Interactive REPL');
        $this->output->writeln('   <fg=gray>→</>  Install <fg=yellow>psy/psysh</> for a richer experience.');
        $this->output->writeln('   <fg=gray>→</>  Type <fg=yellow>exit</> or press <options=bold>Ctrl+D</> to quit.');
        $this->newLine();

        if (!function_exists('readline')) {
            $this->error('readline extension is not available.');
            return self::FAILURE;
        }

        $__app = $app;

        while (true) {
            $line = readline('>>> ');

            if ($line === false || in_array(trim((string) $line), ['exit', 'quit'], true)) {
                break;
            }

            if (trim((string) $line) === '') {
                continue;
            }

            readline_add_history((string) $line);

            $code = trim((string) $line);
            if (!str_ends_with($code, ';') && !str_ends_with($code, '}')) {
                $code .= ';';
            }

            try {
                $__result = eval($code); // @phpstan-ignore-line
                if ($__result !== null) {
                    $this->io->writeln(var_export($__result, true));
                }
            } catch (\Throwable $e) {
                $this->error(get_class($e) . ': ' . $e->getMessage());
            }
        }

        $this->newLine();
        return self::SUCCESS;
    }
}
