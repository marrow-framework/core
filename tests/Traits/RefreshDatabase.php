<?php

declare(strict_types=1);

namespace Marrow\Tests\Traits;

use Marrow\Database\Connection;
use Marrow\Database\Model;

/**
 * Provides a fresh SQLite in-memory database for each test.
 *
 * Usage in a Pest test file:
 *
 *   uses(RefreshDatabase::class);
 *
 *   beforeEach(function() {
 *       $this->setUpDatabase();
 *   });
 *
 * Override `runMigrations()` to create the schema your tests need.
 */
trait RefreshDatabase
{
    protected Connection $db;

    protected function setUpDatabase(): void
    {
        $this->db = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->db->enableQueryLog();
        Model::setConnection($this->db);
        $this->runMigrations();
    }

    /** Run the migrations needed by this test file. Override per test file. */
    protected function runMigrations(): void
    {
    }

    protected function truncate(string $table): void
    {
        $this->db->statement("DELETE FROM {$table}");
    }
}
