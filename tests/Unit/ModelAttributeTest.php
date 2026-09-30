<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Marrow\Database\Connection;
use Marrow\Database\Model;
use Marrow\Tests\Unit\Fixtures\AttributeArticleModel;

// ── Tests ─────────────────────────────────────────────────────────────────────
//
// #[Table]/#[Column] are an alternative to declaring $table/$fillable/
// $hidden/$casts directly — AttributeArticleModel declares none of those,
// relying entirely on the attributes resolved in Model::applyAttributeConfig().

beforeEach(function () {
    $conn = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
    $conn->statement('
        CREATE TABLE articles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            body TEXT,
            published INTEGER NOT NULL DEFAULT 0,
            internal_notes TEXT,
            created_at TEXT,
            updated_at TEXT
        )
    ');
    Model::setConnection($conn);
});

test('#[Table] resolves the table name with no $table property declared', function () {
    $article = AttributeArticleModel::create(['title' => 'Hello', 'body' => 'World', 'published' => true]);

    expect($article->id)->not->toBeNull();
    expect(AttributeArticleModel::find($article->id))->toBeInstanceOf(AttributeArticleModel::class);
});

test('#[Column] makes a property mass-assignable, without listing it in $fillable', function () {
    $article = AttributeArticleModel::create(['title' => 'Hello', 'body' => 'World', 'published' => true]);

    expect($article->title)->toBe('Hello');
});

test('#[Column(fillable: false)] is not mass-assignable', function () {
    $article = AttributeArticleModel::create([
        'title' => 'Hello',
        'body' => 'World',
        'published' => true,
        'internal_notes' => 'should not be set',
    ]);

    expect($article->internal_notes)->toBeNull();
});

test('#[Column(hidden: true)] is excluded from toArray()', function () {
    $article = AttributeArticleModel::create(['title' => 'Hello', 'body' => 'World', 'published' => true]);
    $article->forceFill(['internal_notes' => 'secret']);

    expect($article->toArray())->not->toHaveKey('internal_notes');
});

test('#[Column(cast: ...)] applies the cast', function () {
    $article = AttributeArticleModel::create(['title' => 'Hello', 'body' => 'World', 'published' => 1]);

    expect($article->published)->toBeBool();
    expect($article->published)->toBeTrue();
});
