<?php

declare(strict_types=1);

namespace Marrow\Console\Commands;

use Marrow\Console\Command;

/**
 * Clears the compiled Twig template cache and the application cache store.
 */
class CacheClearCommand extends Command
{
    protected string $signature = 'cache:clear';
    protected string $description = 'Clear the application cache (Twig cache + app cache)';

    protected function handle(): int
    {
        // Clear Twig cache
        $twigCache = base_path('storage/cache/twig');
        if (is_dir($twigCache)) {
            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($twigCache, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            ) as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            $this->success('Twig cache cleared.');
        }

        // Clear app cache
        $appCache = base_path('storage/cache/app');
        if (is_dir($appCache)) {
            foreach (glob($appCache . '/*.cache') ?: [] as $file) {
                unlink($file);
            }
            $this->success('App cache cleared.');
        }

        return self::SUCCESS;
    }
}
