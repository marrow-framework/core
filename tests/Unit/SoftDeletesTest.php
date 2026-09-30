<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Marrow\Database\Connection;
use Marrow\Database\Model;
use Marrow\Tests\Unit\Fixtures\SoftDeleteArticleModel;

// ── Tests ─────────────────────────────────────────────────────────────────────
//
// SoftDeletes::bootSoftDeletes() registers a global scope that excludes
// soft-deleted rows — but only once Model actually runs boot{Trait}() hooks
// (see Model::bootIfNotBooted()). These tests exercise that wiring end to end.

beforeEach(function () {
    $conn = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
    $conn->statement('
        CREATE TABLE trashable_articles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            deleted_at TEXT,
            created_at TEXT,
            updated_at TEXT
        )
    ');
    Model::setConnection($conn);
});

test('a soft-deleted row disappears from all() and find()', function () {
    $article = SoftDeleteArticleModel::create(['title' => 'Keep me']);
    $trashed = SoftDeleteArticleModel::create(['title' => 'Trash me']);

    $trashed->delete();

    expect(SoftDeleteArticleModel::all())->toHaveCount(1);
    expect(SoftDeleteArticleModel::find($trashed->id))->toBeNull();
    expect(SoftDeleteArticleModel::find($article->id))->not->toBeNull();
});

test('the row still physically exists after a soft delete', function () {
    $article = SoftDeleteArticleModel::create(['title' => 'Trash me']);
    $article->delete();

    $raw = SoftDeleteArticleModel::withTrashed()->where('id', $article->id)->first();
    expect($raw)->not->toBeNull();
    expect($raw->trashed())->toBeTrue();
});

test('withTrashed() includes soft-deleted rows', function () {
    $kept    = SoftDeleteArticleModel::create(['title' => 'Keep me']);
    $trashed = SoftDeleteArticleModel::create(['title' => 'Trash me']);
    $trashed->delete();

    expect(SoftDeleteArticleModel::withTrashed()->get())->toHaveCount(2);
});

test('onlyTrashed() returns only soft-deleted rows', function () {
    $kept    = SoftDeleteArticleModel::create(['title' => 'Keep me']);
    $trashed = SoftDeleteArticleModel::create(['title' => 'Trash me']);
    $trashed->delete();

    $only = SoftDeleteArticleModel::onlyTrashed()->get();
    expect($only)->toHaveCount(1);
    expect($only->first()->id)->toBe($trashed->id);
});

test('restore() brings a soft-deleted row back into normal queries', function () {
    $article = SoftDeleteArticleModel::create(['title' => 'Trash me']);
    $article->delete();
    expect(SoftDeleteArticleModel::find($article->id))->toBeNull();

    $article->restore();

    expect(SoftDeleteArticleModel::find($article->id))->not->toBeNull();
    expect($article->trashed())->toBeFalse();
});

test('forceDelete() removes the row for good, even from withTrashed()', function () {
    $article = SoftDeleteArticleModel::create(['title' => 'Gone forever']);
    $article->forceDelete();

    expect(SoftDeleteArticleModel::withTrashed()->where('id', $article->id)->first())->toBeNull();
});
