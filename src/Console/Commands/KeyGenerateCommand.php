<?php

declare(strict_types=1);

namespace Marrow\Console\Commands;

use Marrow\Console\Command;

/**
 * Generates a random 32-byte APP_KEY and writes it to .env (creating the
 * file if it doesn't exist). `--show` prints the key without writing it.
 */
class KeyGenerateCommand extends Command
{
    protected string $signature = 'key:generate {--show}';
    protected string $description = 'Generate a new application key and write it to .env';

    protected function handle(): int
    {
        $key = 'base64:' . base64_encode(random_bytes(32));
        $env = base_path('.env');

        if ($this->option('show')) {
            $this->line("<comment>{$key}</comment>");
            return self::SUCCESS;
        }

        if (!is_file($env)) {
            $this->error('.env file not found. Run: cp .env.example .env');
            return self::FAILURE;
        }

        $content = file_get_contents($env);
        if (str_contains((string) $content, 'APP_KEY=')) {
            $content = preg_replace('/^APP_KEY=.*$/m', "APP_KEY={$key}", (string) $content);
        } else {
            $content .= "\nAPP_KEY={$key}";
        }

        file_put_contents($env, $content);
        $this->success("Application key set successfully.");
        return self::SUCCESS;
    }
}
