<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Marrow\Database\Connection;
use Marrow\Queue\QueueManager;
use Marrow\Tests\Unit\Fixtures\TestQueueJob;

// ── Tests ─────────────────────────────────────────────────────────────────────
//
// pop() used to unserialize() the stored payload with no allowed_classes
// restriction — a PHP object injection gap if the `jobs` table were ever
// reachable by anything other than push()/later(). It's now restricted to
// Job subclasses only.

beforeEach(function () {
    $this->conn = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
    $this->conn->statement('
        CREATE TABLE jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            queue TEXT NOT NULL,
            payload TEXT NOT NULL,
            attempts INTEGER NOT NULL DEFAULT 0,
            reserved_at INTEGER,
            available_at INTEGER NOT NULL,
            created_at INTEGER NOT NULL
        )
    ');
    $this->queue = new QueueManager($this->conn);
});

test('a pushed job round-trips through pop() with the right payload', function () {
    $this->queue->push(new TestQueueJob('round-trip'));

    $reserved = $this->queue->pop();

    expect($reserved)->not->toBeNull();
    expect($reserved->job)->toBeInstanceOf(TestQueueJob::class);
    expect($reserved->job->note)->toBe('round-trip');
});

test('a payload naming a non-Job class is rejected instead of being instantiated', function () {
    $this->conn->insert('jobs', [
        'queue' => 'default',
        'payload' => serialize(new \stdClass()),
        'attempts' => 0,
        'available_at' => time(),
        'created_at' => time(),
    ]);

    expect(fn () => $this->queue->pop())->toThrow(\RuntimeException::class, 'Invalid job payload');
});

test('an unrelated class embedded in the payload is not instantiated (object-injection guard)', function () {
    // Simulate a tampered/forged payload naming a class that isn't a Job at
    // all — allowed_classes must reject it before it's ever constructed.
    $forged = serialize(new \Marrow\Tests\Unit\Fixtures\SimpleService());

    $this->conn->insert('jobs', [
        'queue' => 'default',
        'payload' => $forged,
        'attempts' => 0,
        'available_at' => time(),
        'created_at' => time(),
    ]);

    expect(fn () => $this->queue->pop())->toThrow(\RuntimeException::class, 'Invalid job payload');
});
