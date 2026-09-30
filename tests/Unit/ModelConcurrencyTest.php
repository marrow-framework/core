<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Marrow\Database\Connection;
use Marrow\Database\Model;
use Marrow\Tests\Unit\Fixtures\RaceModel;

// ── Tests ─────────────────────────────────────────────────────────────────────
//
// firstOrCreate()/updateOrCreate() read then write, which has a race window
// between the SELECT and the INSERT. Application code alone can't close that
// window — it requires a unique constraint at the database level — but the
// methods must at least not crash when the constraint catches a genuine
// concurrent insert, and must not swallow a real (non-race) integrity error.

beforeEach(function () {
    $conn = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
    $conn->statement('
        CREATE TABLE race_models (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT UNIQUE,
            name TEXT,
            created_at TEXT,
            updated_at TEXT
        )
    ');
    Model::setConnection($conn);
    $this->conn = $conn;
});

test('firstOrCreate returns the existing row without attempting an insert', function () {
    $existing = RaceModel::create(['email' => 'alice@test.com', 'name' => 'Alice']);

    $found = RaceModel::firstOrCreate(['email' => 'alice@test.com'], ['name' => 'Someone else']);

    expect($found->id)->toBe($existing->id);
    expect($found->name)->toBe('Alice'); // values ignored when a match is found
    expect(RaceModel::query()->count())->toBe(1);
});

test('updateOrCreate updates the existing row in place', function () {
    $existing = RaceModel::create(['email' => 'alice@test.com', 'name' => 'Alice']);

    $updated = RaceModel::updateOrCreate(['email' => 'alice@test.com'], ['name' => 'Alice Updated']);

    expect($updated->id)->toBe($existing->id);
    expect(RaceModel::find($existing->id)->name)->toBe('Alice Updated');
    expect(RaceModel::query()->count())->toBe(1);
});

test('firstOrCreate creates a new row when none matches', function () {
    $model = RaceModel::firstOrCreate(['email' => 'new@test.com'], ['name' => 'New']);

    expect($model->exists())->toBeTrue();
    expect(RaceModel::query()->count())->toBe(1);
});

test('a genuine unique-constraint violation unrelated to a race still propagates', function () {
    // A row already occupies this email, but under a *different* name — so
    // the lookup by name finds nothing, create() is attempted, and it fails
    // for a real reason (an actual data conflict), not because a concurrent
    // call already created the row being looked for.
    RaceModel::create(['email' => 'taken@test.com', 'name' => 'Existing']);

    expect(fn () => RaceModel::firstOrCreate(['name' => 'Brand New'], ['email' => 'taken@test.com']))
        ->toThrow(UniqueConstraintViolationException::class);

    // No half-created row was left behind.
    expect(RaceModel::query()->where('name', 'Brand New')->count())->toBe(0);
});
