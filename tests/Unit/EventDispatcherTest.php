<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Marrow\Container;
use Marrow\Events\Dispatcher;
use Marrow\Tests\Unit\Fixtures\SomethingElse;
use Marrow\Tests\Unit\Fixtures\SomethingHappened;

// ── Tests ─────────────────────────────────────────────────────────────────────

beforeEach(function () {
    $this->dispatcher = new Dispatcher(new Container());
});

test('closure listener receives event', function () {
    $received = null;
    $this->dispatcher->listen(SomethingHappened::class, function (SomethingHappened $e) use (&$received) {
        $received = $e->payload;
    });
    $this->dispatcher->dispatch(new SomethingHappened('hello'));
    expect($received)->toBe('hello');
});

test('multiple listeners all called', function () {
    $log = [];
    $this->dispatcher->listen(SomethingHappened::class, function () use (&$log) { $log[] = 'a'; });
    $this->dispatcher->listen(SomethingHappened::class, function () use (&$log) { $log[] = 'b'; });
    $this->dispatcher->dispatch(new SomethingHappened('x'));
    expect($log)->toBe(['a', 'b']);
});

test('listener returning false stops propagation', function () {
    $log = [];
    $this->dispatcher->listen(SomethingHappened::class, function () use (&$log) {
        $log[] = 'first';
        return false;
    });
    $this->dispatcher->listen(SomethingHappened::class, function () use (&$log) {
        $log[] = 'second';
    });
    $this->dispatcher->dispatch(new SomethingHappened('stop'));
    expect($log)->toBe(['first']);
});

test('until returns first non-null result', function () {
    $this->dispatcher->listen(SomethingHappened::class, fn () => null);
    $this->dispatcher->listen(SomethingHappened::class, fn () => 'found');
    $this->dispatcher->listen(SomethingHappened::class, fn () => 'too late');
    expect($this->dispatcher->until(new SomethingHappened('q')))->toBe('found');
});

test('no listener dispatches silently', function () {
    $this->dispatcher->dispatch(new SomethingElse());
    expect(true)->toBeTrue();
});

test('unrelated event does not trigger listener', function () {
    $called = false;
    $this->dispatcher->listen(SomethingHappened::class, function () use (&$called) {
        $called = true;
    });
    $this->dispatcher->dispatch(new SomethingElse());
    expect($called)->toBeFalse();
});
