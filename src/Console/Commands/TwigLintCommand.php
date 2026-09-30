<?php

declare(strict_types=1);

namespace Marrow\Console\Commands;

use Marrow\Console\Command;
use Marrow\Template\Engine;
use Twig\Error\SyntaxError;

/**
 * Compiles every Twig template found under resources/views and under each
 * module's Views directory, to catch syntax errors without actually
 * rendering a request.
 */
class TwigLintCommand extends Command
{
    protected string $signature = 'twig:lint {path=}';
    protected string $description = 'Verify the syntax of Twig templates';

    public function __construct(private readonly Engine $view)
    {
        parent::__construct();
    }

    protected function handle(): int
    {
        $basePath = base_path('resources/views');
        $patterns = [
            $basePath . '/**/*.twig',
            base_path('modules') . '/**/Views/**/*.twig',
        ];

        $files = [];
        foreach ($patterns as $pattern) {
            foreach (glob($pattern, GLOB_BRACE) ?: [] as $file) {
                $files[] = $file;
            }
        }

        // Also use recursive iterator
        foreach ([base_path('resources/views'), base_path('modules')] as $dir) {
            if (!is_dir($dir))
                continue;
            $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($iter as $file) {
                if ($file->getExtension() === 'twig') {
                    $files[] = $file->getPathname();
                }
            }
        }

        $files = array_unique($files);
        $errors = 0;
        $twig = $this->view->getTwig();

        foreach ($files as $file) {
            try {
                $source = file_get_contents($file);
                $twig->tokenize(new \Twig\Source($source, $file));
                $this->line("<info>OK</info>  {$file}");
            } catch (SyntaxError $e) {
                $this->error("FAIL {$file}: " . $e->getMessage());
                $errors++;
            }
        }

        if ($errors > 0) {
            $this->error("{$errors} template(s) have syntax errors.");
            return self::FAILURE;
        }

        $this->success('All templates are valid.');
        return self::SUCCESS;
    }
}
