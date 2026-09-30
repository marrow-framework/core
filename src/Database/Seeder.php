<?php

declare(strict_types=1);

namespace Marrow\Database;

/**
 * Base seeder class.
 */
abstract class Seeder
{
    abstract public function run(): void;

    protected function call(string $seederClass): void
    {
        $seeder = new $seederClass();
        $seeder->run();
    }
}
