<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Marrow\Database\Schema\Table;

// ── Tests ─────────────────────────────────────────────────────────────────────
//
// ForeignIdDefinition::registerForeign() only ever populated Table's $foreigns
// when called — but nothing called it. Table::foreignId() now tracks pending
// definitions and finalizeForeignIds() (called by Schema::buildTable() before
// reading getForeigns()) resolves them.

test('foreignId()->constrained() registers a foreign key once finalized', function () {
    $table = new Table('posts');
    $table->foreignId('user_id')->constrained();
    $table->finalizeForeignIds();

    $foreigns = $table->getForeigns();
    expect($foreigns)->toHaveCount(1);
    expect($foreigns[0])->toMatchArray([
        'column' => 'user_id',
        'table' => 'users',
        'ref_column' => 'id',
        'on_delete' => null,
    ]);
});

test('constrained() with explicit table/column and cascadeOnDelete()', function () {
    $table = new Table('comments');
    $table->foreignId('author_id')->constrained('users', 'uuid')->cascadeOnDelete();
    $table->finalizeForeignIds();

    $foreigns = $table->getForeigns();
    expect($foreigns)->toHaveCount(1);
    expect($foreigns[0])->toMatchArray([
        'column' => 'author_id',
        'table' => 'users',
        'ref_column' => 'uuid',
        'on_delete' => 'CASCADE',
    ]);
});

test('foreignId() without constrained() registers no foreign key', function () {
    $table = new Table('posts');
    $table->foreignId('external_ref'); // plain column, no FK intended
    $table->finalizeForeignIds();

    expect($table->getForeigns())->toBe([]);
});

test('nullOnDelete() sets the ON DELETE SET NULL clause', function () {
    $table = new Table('posts');
    $table->foreignId('editor_id')->constrained()->nullable()->nullOnDelete();
    $table->finalizeForeignIds();

    expect($table->getForeigns()[0]['on_delete'])->toBe('SET NULL');
});

test('multiple foreignId() calls on the same table all get finalized', function () {
    $table = new Table('posts');
    $table->foreignId('user_id')->constrained();
    $table->foreignId('category_id')->constrained();
    $table->finalizeForeignIds();

    expect($table->getForeigns())->toHaveCount(2);
});
