<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Marrow\Config\Repository;

// ── Tests ─────────────────────────────────────────────────────────────────────

beforeEach(function () {
    $this->config = new Repository();
});

test('set and get round-trip a top-level value', function () {
    $this->config->set('app.name', 'Marrow');
    expect($this->config->get('app.name'))->toBe('Marrow');
});

test('get returns the default for a missing key', function () {
    expect($this->config->get('missing.key', 'fallback'))->toBe('fallback');
});

test('has reflects presence for nested keys', function () {
    expect($this->config->has('db.host'))->toBeFalse();
    $this->config->set('db.host', 'localhost');
    expect($this->config->has('db.host'))->toBeTrue();
});

test('set builds intermediate arrays as needed', function () {
    $this->config->set('a.b.c', 'deep');
    expect($this->config->get('a.b.c'))->toBe('deep');
    expect($this->config->get('a.b'))->toBe(['c' => 'deep']);
});

test('set on an existing non-array value overwrites it with a nested structure', function () {
    $this->config->set('x', 'scalar');
    $this->config->set('x.y', 'nested');
    expect($this->config->get('x.y'))->toBe('nested');
});

test('all returns the full underlying array', function () {
    $this->config->set('a', 1);
    $this->config->set('b', 2);
    expect($this->config->all())->toBe(['a' => 1, 'b' => 2]);
});
