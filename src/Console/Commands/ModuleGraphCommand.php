<?php

declare(strict_types=1);

namespace Marrow\Console\Commands;

use Marrow\Console\Command;
use Marrow\Module\ModuleManager;

/**
 * Prints the module dependency graph (imports/exports) computed by
 * ModuleManager; `--check` additionally validates it for cycles or
 * missing imports.
 */
class ModuleGraphCommand extends Command
{
    protected string $signature = 'module:graph {--check}';
    protected string $description = 'Display the module dependency graph';

    public function __construct(private readonly ModuleManager $manager)
    {
        parent::__construct();
    }

    protected function handle(): int
    {
        try {
            $graph = $this->manager->renderGraph();
            $this->line($graph);

            if ($this->option('check')) {
                $this->success('Module graph is valid. No cycles or missing imports detected.');
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
