<?php

declare(strict_types=1);

use Marrow\Support\Collection;

function col(array $items = []): Collection
{
    return new Collection($items);
}

test('map doubles values', function () {
    expect(col([1, 2, 3])->map(fn ($x) => $x * 2)->values()->toArray())->toBe([2, 4, 6]);
});

test('filter keeps even numbers', function () {
    expect(col([1, 2, 3, 4])->filter(fn ($x) => $x % 2 === 0)->values()->toArray())->toBe([2, 4]);
});

test('reject removes even numbers', function () {
    expect(col([1, 2, 3, 4])->reject(fn ($x) => $x % 2 === 0)->values()->toArray())->toBe([1, 3]);
});

test('reduce sums values', function () {
    expect(col([1, 2, 3, 4])->reduce(fn ($carry, $x) => $carry + $x, 0))->toBe(10);
});

test('pluck extracts named key', function () {
    $data = [['name' => 'Alice'], ['name' => 'Bob']];
    expect(col($data)->pluck('name')->values()->toArray())->toBe(['Alice', 'Bob']);
});

test('first and last', function () {
    $c = col([10, 20, 30]);
    expect($c->first())->toBe(10);
    expect($c->last())->toBe(30);
});

test('contains returns correct boolean', function () {
    $c = col([1, 2, 3]);
    expect($c->contains(2))->toBeTrue();
    expect($c->contains(99))->toBeFalse();
});

test('count returns item count', function () {
    expect(col([1, 2, 3])->count())->toBe(3);
});

test('isEmpty and isNotEmpty', function () {
    expect(col([])->isEmpty())->toBeTrue();
    expect(col([1])->isEmpty())->toBeFalse();
    expect(col([1])->isNotEmpty())->toBeTrue();
});

test('unique removes duplicates', function () {
    expect(col([1, 2, 2, 3, 3, 3])->unique()->values()->toArray())->toBe([1, 2, 3]);
});

test('chunk splits into groups', function () {
    $chunks = col([1, 2, 3, 4, 5])->chunk(2);
    expect($chunks->count())->toBe(3);
    expect($chunks->first()->toArray())->toBe([1, 2]);
});

test('sum', function () {
    expect(col([1, 2, 3, 4, 5])->sum())->toBe(15.0);
});

test('avg', function () {
    expect(col([1, 2, 3, 4, 5])->avg())->toBe(3.0);
});

test('min and max', function () {
    $c = col([3, 1, 4, 1, 5, 9]);
    expect($c->min())->toBe(1.0);
    expect($c->max())->toBe(9.0);
});

test('sortBy sorts by key', function () {
    $data   = [['n' => 3], ['n' => 1], ['n' => 2]];
    $sorted = col($data)->sortBy('n')->values()->pluck('n')->toArray();
    expect($sorted)->toBe([1, 2, 3]);
});

test('groupBy groups by key', function () {
    $data = [
        ['type' => 'a', 'v' => 1],
        ['type' => 'b', 'v' => 2],
        ['type' => 'a', 'v' => 3],
    ];
    $grouped = col($data)->groupBy('type');
    expect($grouped->count())->toBe(2);
    expect($grouped->first()->count())->toBe(2);
});

test('toJson encodes to JSON', function () {
    expect(col([1, 2, 3])->toJson())->toBe('[1,2,3]');
});

test('merge combines collections', function () {
    $merged = col([1, 2])->merge(col([3, 4]));
    expect($merged->values()->toArray())->toBe([1, 2, 3, 4]);
});

test('take and skip', function () {
    $c = col([1, 2, 3, 4, 5]);
    expect($c->take(2)->values()->toArray())->toBe([1, 2]);
    expect($c->skip(2)->values()->toArray())->toBe([3, 4, 5]);
});

test('flatten nested arrays', function () {
    expect(col([[1, 2], [3, [4, 5]]])->flatten()->values()->toArray())->toBe([1, 2, 3, 4, 5]);
});
