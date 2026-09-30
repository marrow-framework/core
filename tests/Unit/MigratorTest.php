<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Marrow\Database\Connection;
use Marrow\Database\Migrations\Migrator;

// ── Tests ─────────────────────────────────────────────────────────────────────
//
// run()/rollback() now wrap each migration's up()/down() together with its
// migrations-table tracking write in a transaction, so a migration that
// throws partway is never recorded as ran (which would otherwise skip it
// silently on the next run despite the schema change never landing).

beforeEach(function () {
    $this->conn = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
    $this->dir  = sys_get_temp_dir() . '/marrow-migrator-test-' . uniqid();
    mkdir($this->dir, 0755, true);
});

afterEach(function () {
    foreach (glob($this->dir . '/*.php') ?: [] as $f) {
        unlink($f);
    }
    @rmdir($this->dir);
});

function writeMigration(string $dir, string $name, string $upBody, string $downBody = ''): void
{
    file_put_contents($dir . "/{$name}.php", <<<PHP
<?php
return new class extends \\Marrow\\Database\\Migrations\\Migration {
    public function up(): void { {$upBody} }
    public function down(): void { {$downBody} }
};
PHP);
}

test('a successful migration is applied and tracked', function () {
    writeMigration($this->dir, '2024_01_01_000000_ok', '');

    $migrator = new Migrator($this->conn);
    $ran = $migrator->run($this->dir);

    expect($ran)->toHaveCount(1);
    $status = $migrator->status($this->dir);
    expect($status[0]['ran'])->toBeTrue();
});

test('a migration whose up() throws is not recorded as ran, and the exception propagates', function () {
    writeMigration($this->dir, '2024_01_01_000001_broken', 'throw new \RuntimeException("boom");');

    $migrator = new Migrator($this->conn);

    expect(fn () => $migrator->run($this->dir))->toThrow(\RuntimeException::class, 'boom');

    $status = $migrator->status($this->dir);
    expect($status[0]['ran'])->toBeFalse();
});

test('rollback removes the tracking row and calls down()', function () {
    writeMigration($this->dir, '2024_01_01_000002_reversible', '', '');

    $migrator = new Migrator($this->conn);
    $migrator->run($this->dir);
    expect($migrator->status($this->dir)[0]['ran'])->toBeTrue();

    $migrator->rollback($this->dir);
    expect($migrator->status($this->dir)[0]['ran'])->toBeFalse();
});
