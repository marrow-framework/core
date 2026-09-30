<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Marrow\Scheduling\ScheduledEvent;

// ── Tests ─────────────────────────────────────────────────────────────────────

test('without withoutOverlapping, run() always executes and returns true', function () {
    $calls = 0;
    $event = new ScheduledEvent(function () use (&$calls) {
        $calls++;
    }, 'test-event-' . uniqid());

    expect($event->run())->toBeTrue();
    expect($event->run())->toBeTrue();
    expect($calls)->toBe(2);
});

test('withoutOverlapping lets a second concurrent run be skipped while the first holds the lock', function () {
    $description = 'test-overlap-' . uniqid();

    $inner = new ScheduledEvent(fn () => null, $description);
    $inner->withoutOverlapping();

    // Acquire the lock directly (private API via reflection) to simulate a
    // first run still in progress, then confirm a second event sharing the
    // same description is skipped rather than running concurrently.
    $ref = new \ReflectionMethod($inner, 'acquireLock');
    expect($ref->invoke($inner))->toBeTrue();

    $calls = 0;
    $second = new ScheduledEvent(function () use (&$calls) {
        $calls++;
    }, $description);
    $second->withoutOverlapping();

    expect($second->run())->toBeFalse();
    expect($calls)->toBe(0);

    (new \ReflectionMethod($inner, 'releaseLock'))->invoke($inner);

    // Lock released — now it can run.
    expect($second->run())->toBeTrue();
    expect($calls)->toBe(1);
});

test('withoutOverlapping releases the lock even when the callback throws', function () {
    $description = 'test-overlap-throw-' . uniqid();

    $failing = new ScheduledEvent(function () {
        throw new \RuntimeException('boom');
    }, $description);
    $failing->withoutOverlapping();

    expect(fn () => $failing->run())->toThrow(\RuntimeException::class, 'boom');

    $calls = 0;
    $next = new ScheduledEvent(function () use (&$calls) {
        $calls++;
    }, $description);
    $next->withoutOverlapping();

    expect($next->run())->toBeTrue();
    expect($calls)->toBe(1);
});
