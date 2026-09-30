<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Marrow\Support\Collection;
use Marrow\Support\Paginator;

// ── Tests ─────────────────────────────────────────────────────────────────────

test('exposes the basic pagination figures', function () {
    $p = new Paginator(new Collection([1, 2, 3]), 25, 10, 2);

    expect($p->items()->toArray())->toBe([1, 2, 3]);
    expect($p->total())->toBe(25);
    expect($p->perPage())->toBe(10);
    expect($p->currentPage())->toBe(2);
    expect($p->lastPage())->toBe(3);
});

test('hasMorePages and onFirstPage reflect position', function () {
    $first = new Paginator(new Collection([]), 25, 10, 1);
    expect($first->onFirstPage())->toBeTrue();
    expect($first->hasMorePages())->toBeTrue();

    $last = new Paginator(new Collection([]), 25, 10, 3);
    expect($last->onFirstPage())->toBeFalse();
    expect($last->hasMorePages())->toBeFalse();
});

test('links() is empty when there is only one page', function () {
    $p = new Paginator(new Collection([1]), 1, 10, 1);
    expect($p->links())->toBe('');
});

test('links() renders page links when there is more than one page', function () {
    $previous = $_GET;
    $_GET = [];

    $p = new Paginator(new Collection([]), 25, 10, 2);
    $html = $p->links();

    expect($html)->toContain('Précédent');
    expect($html)->toContain('Suivant');
    expect($html)->toContain('page=1');
    expect($html)->toContain('page=3');

    $_GET = $previous;
});

test('jsonSerialize exposes the expected envelope shape', function () {
    $p = new Paginator(new Collection(['a', 'b']), 12, 5, 2);

    expect($p->jsonSerialize())->toBe([
        'data' => ['a', 'b'],
        'total' => 12,
        'per_page' => 5,
        'current_page' => 2,
        'last_page' => 3,
    ]);
});
