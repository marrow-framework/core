<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Marrow\Database\Connection;
use Marrow\Database\Model;
use Marrow\Tests\Unit\Fixtures\RelationAuthorModel;
use Marrow\Tests\Unit\Fixtures\RelationCountryModel;
use Marrow\Tests\Unit\Fixtures\RelationPostModel;
use Marrow\Tests\Unit\Fixtures\RelationTagModel;

// ── Tests ─────────────────────────────────────────────────────────────────────
//
// BelongsToMany and HasManyThrough used to build raw SQL directly and bypass
// ModelQueryBuilder, so global scopes (e.g. SoftDeletes) never applied to
// them even though HasOne/HasMany/BelongsTo respected them. These tests
// exercise both relation types through joins, pivot columns, eager loading,
// and confirm the SoftDeletes scope is now actually honoured.

beforeEach(function () {
    $conn = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
    $conn->statement('CREATE TABLE rel_countries (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, created_at TEXT, updated_at TEXT)');
    $conn->statement('CREATE TABLE rel_authors (id INTEGER PRIMARY KEY AUTOINCREMENT, country_id INTEGER, name TEXT, created_at TEXT, updated_at TEXT)');
    $conn->statement('CREATE TABLE rel_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, author_id INTEGER, title TEXT, deleted_at TEXT, created_at TEXT, updated_at TEXT)');
    $conn->statement('CREATE TABLE rel_tags (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, deleted_at TEXT, created_at TEXT, updated_at TEXT)');
    $conn->statement('CREATE TABLE rel_post_tag (post_id INTEGER, tag_id INTEGER, sort_order INTEGER)');
    Model::setConnection($conn);
    $this->conn = $conn;
});

// ── BelongsToMany ────────────────────────────────────────────────────────────

test('belongsToMany returns related models joined through the pivot table', function () {
    $post = RelationPostModel::create(['title' => 'Post A']);
    $php  = RelationTagModel::create(['name' => 'php']);
    $orm  = RelationTagModel::create(['name' => 'orm']);
    $post->tags()->attach([$php->id, $orm->id]);

    $names = $post->tags()->getResults()->pluck('name')->toArray();
    sort($names);
    expect($names)->toBe(['orm', 'php']);
});

test('belongsToMany excludes soft-deleted related models', function () {
    $post = RelationPostModel::create(['title' => 'Post A']);
    $php  = RelationTagModel::create(['name' => 'php']);
    $orm  = RelationTagModel::create(['name' => 'orm']);
    $post->tags()->attach([$php->id, $orm->id]);

    $orm->delete(); // soft delete

    $names = $post->tags()->getResults()->pluck('name')->toArray();
    expect($names)->toBe(['php']);
});

test('belongsToMany eager loading also excludes soft-deleted related models', function () {
    $post1 = RelationPostModel::create(['title' => 'Post A']);
    $post2 = RelationPostModel::create(['title' => 'Post B']);
    $php   = RelationTagModel::create(['name' => 'php']);
    $orm   = RelationTagModel::create(['name' => 'orm']);
    $post1->tags()->attach([$php->id, $orm->id]);
    $post2->tags()->attach([$orm->id]);

    $orm->delete();

    $posts = RelationPostModel::with('tags')->get();
    $byId  = $posts->keyBy('id');

    expect($byId[$post1->id]->tags->pluck('name')->toArray())->toBe(['php']);
    expect($byId[$post2->id]->tags->pluck('name')->toArray())->toBe([]);
});

test('withPivot() exposes pivot columns on the related model', function () {
    $post = RelationPostModel::create(['title' => 'Post A']);
    $tag  = RelationTagModel::create(['name' => 'php']);
    $post->tags()->attach([$tag->id], ['sort_order' => 3]);

    $result = $post->tags()->getResults()->first();
    expect((int) $result->pivot_sort_order)->toBe(3);
});

test('detach() without ids removes all pivot rows for the parent', function () {
    $post = RelationPostModel::create(['title' => 'Post A']);
    $php  = RelationTagModel::create(['name' => 'php']);
    $orm  = RelationTagModel::create(['name' => 'orm']);
    $post->tags()->attach([$php->id, $orm->id]);

    $post->tags()->detach();

    expect($post->tags()->getResults())->toHaveCount(0);
});

test('sync() replaces the pivot set', function () {
    $post = RelationPostModel::create(['title' => 'Post A']);
    $php  = RelationTagModel::create(['name' => 'php']);
    $orm  = RelationTagModel::create(['name' => 'orm']);
    $post->tags()->attach([$php->id]);

    $post->tags()->sync([$orm->id]);

    expect($post->tags()->getResults()->pluck('name')->toArray())->toBe(['orm']);
});

// ── HasManyThrough ───────────────────────────────────────────────────────────

test('hasManyThrough returns related models across the through table', function () {
    $country = RelationCountryModel::create(['name' => 'Wonderland']);
    $author  = RelationAuthorModel::create(['country_id' => $country->id, 'name' => 'Alice']);
    RelationPostModel::create(['author_id' => $author->id, 'title' => 'Post A']);
    RelationPostModel::create(['author_id' => $author->id, 'title' => 'Post B']);

    $titles = $country->posts()->getResults()->pluck('title')->toArray();
    sort($titles);
    expect($titles)->toBe(['Post A', 'Post B']);
});

test('hasManyThrough excludes soft-deleted related models', function () {
    $country = RelationCountryModel::create(['name' => 'Wonderland']);
    $author  = RelationAuthorModel::create(['country_id' => $country->id, 'name' => 'Alice']);
    $post    = RelationPostModel::create(['author_id' => $author->id, 'title' => 'Post A']);
    RelationPostModel::create(['author_id' => $author->id, 'title' => 'Post B']);

    $post->delete(); // soft delete

    expect($country->posts()->getResults()->pluck('title')->toArray())->toBe(['Post B']);
});

test('hasManyThrough eager loading matches results back to the right parent', function () {
    $wonderland = RelationCountryModel::create(['name' => 'Wonderland']);
    $oz         = RelationCountryModel::create(['name' => 'Oz']);
    $alice      = RelationAuthorModel::create(['country_id' => $wonderland->id, 'name' => 'Alice']);
    $dorothy    = RelationAuthorModel::create(['country_id' => $oz->id, 'name' => 'Dorothy']);
    RelationPostModel::create(['author_id' => $alice->id, 'title' => 'Wonderland Post']);
    RelationPostModel::create(['author_id' => $dorothy->id, 'title' => 'Oz Post']);

    $countries = RelationCountryModel::with('posts')->get()->keyBy('id');

    expect($countries[$wonderland->id]->posts->pluck('title')->toArray())->toBe(['Wonderland Post']);
    expect($countries[$oz->id]->posts->pluck('title')->toArray())->toBe(['Oz Post']);
});

test('withCount() on a hasManyThrough relation counts across the through table', function () {
    $country = RelationCountryModel::create(['name' => 'Wonderland']);
    $author  = RelationAuthorModel::create(['country_id' => $country->id, 'name' => 'Alice']);
    RelationPostModel::create(['author_id' => $author->id, 'title' => 'Post A']);
    RelationPostModel::create(['author_id' => $author->id, 'title' => 'Post B']);

    $withCount = RelationCountryModel::query()->withCount('posts')->get()->first();
    expect((int) $withCount->posts_count)->toBe(2);
});

test('withCount() on a hasManyThrough relation excludes soft-deleted related models', function () {
    $country = RelationCountryModel::create(['name' => 'Wonderland']);
    $author  = RelationAuthorModel::create(['country_id' => $country->id, 'name' => 'Alice']);
    $post    = RelationPostModel::create(['author_id' => $author->id, 'title' => 'Post A']);
    RelationPostModel::create(['author_id' => $author->id, 'title' => 'Post B']);

    $post->delete(); // soft delete

    $withCount = RelationCountryModel::query()->withCount('posts')->get()->first();
    expect((int) $withCount->posts_count)->toBe(1);
});

// ── withCount() scope-awareness (HasMany / BelongsToMany / BelongsTo) ────────

test('withCount() on a hasMany relation excludes soft-deleted related models', function () {
    $author = RelationAuthorModel::create(['name' => 'Alice']);
    $post   = RelationPostModel::create(['author_id' => $author->id, 'title' => 'Post A']);
    RelationPostModel::create(['author_id' => $author->id, 'title' => 'Post B']);

    $post->delete(); // soft delete

    $withCount = RelationAuthorModel::query()->withCount('posts')->get()->first();
    expect((int) $withCount->posts_count)->toBe(1);
});

test('withCount() on a belongsToMany relation excludes soft-deleted related models', function () {
    $post = RelationPostModel::create(['title' => 'Post A']);
    $php  = RelationTagModel::create(['name' => 'php']);
    $orm  = RelationTagModel::create(['name' => 'orm']);
    $post->tags()->attach([$php->id, $orm->id]);

    $orm->delete(); // soft delete

    $withCount = RelationPostModel::query()->withCount('tags')->get()->first();
    expect((int) $withCount->tags_count)->toBe(1);
});

test('withCount() on a belongsTo relation reflects whether the foreign key is set, without querying', function () {
    $author = RelationAuthorModel::create(['name' => 'Alice']);
    $linked = RelationPostModel::create(['author_id' => $author->id, 'title' => 'Linked']);
    $orphan = RelationPostModel::create(['title' => 'Orphan']);

    $withCount = RelationPostModel::query()->withCount('author')->get()->keyBy('id');
    expect((int) $withCount[$linked->id]->author_count)->toBe(1);
    expect((int) $withCount[$orphan->id]->author_count)->toBe(0);
});
