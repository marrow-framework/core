<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Marrow\Database\Connection;
use Marrow\Database\Model;
use Marrow\Tests\Unit\Fixtures\ArticleModel;
use RuntimeException;

// ── Tests ─────────────────────────────────────────────────────────────────────

beforeEach(function () {
    $conn = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
    $conn->statement('
        CREATE TABLE articles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            body TEXT,
            published INTEGER NOT NULL DEFAULT 0,
            created_at TEXT,
            updated_at TEXT
        )
    ');
    Model::setConnection($conn);
    $this->conn = $conn;
});

test('create and find', function () {
    $article = ArticleModel::create(['title' => 'Hello', 'body' => 'World', 'published' => false]);
    expect($article->id)->not->toBeNull();
    $found = ArticleModel::find($article->id);
    expect($found)->toBeInstanceOf(ArticleModel::class);
    expect($found->title)->toBe('Hello');
});

test('save updates existing record', function () {
    $article        = ArticleModel::create(['title' => 'Draft', 'body' => 'text', 'published' => false]);
    $article->title = 'Updated';
    $article->save();
    expect(ArticleModel::find($article->id)->title)->toBe('Updated');
});

test('delete removes record', function () {
    $article = ArticleModel::create(['title' => 'To Delete', 'body' => '', 'published' => false]);
    $id      = $article->id;
    $article->delete();
    expect(ArticleModel::find($id))->toBeNull();
});

test('all returns all records', function () {
    ArticleModel::create(['title' => 'A', 'body' => '', 'published' => false]);
    ArticleModel::create(['title' => 'B', 'body' => '', 'published' => false]);
    expect(ArticleModel::all())->toHaveCount(2);
});

test('findOrFail throws on missing', function () {
    expect(fn () => ArticleModel::findOrFail(99999))->toThrow(RuntimeException::class);
});

test('dirty tracking', function () {
    $article = ArticleModel::create(['title' => 'Clean', 'body' => '', 'published' => false]);
    $article->refresh();
    expect($article->isDirty())->toBeFalse();
    $article->title = 'Dirty';
    expect($article->isDirty())->toBeTrue();
    expect($article->isDirty('title'))->toBeTrue();
});

test('cast boolean', function () {
    $article = ArticleModel::create(['title' => 'Cast', 'body' => '', 'published' => 1]);
    $found   = ArticleModel::find($article->id);
    expect($found->published)->toBeBool();
    expect($found->published)->toBeTrue();
});

test('firstOrCreate returns same record on second call', function () {
    $a = ArticleModel::firstOrCreate(['title' => 'Unique'], ['body' => 'body1', 'published' => false]);
    $b = ArticleModel::firstOrCreate(['title' => 'Unique'], ['body' => 'body2', 'published' => false]);
    expect($a->id)->toBe($b->id);
    expect(ArticleModel::query()->where('title', '=', 'Unique')->count())->toBe(1);
});

test('toArray hides hidden fields', function () {
    $article = ArticleModel::create(['title' => 'Visible', 'body' => 'secret', 'published' => false]);
    $array   = $article->toArray();
    expect($array)->toHaveKey('title');
    expect($array)->not->toHaveKey('body');
});
