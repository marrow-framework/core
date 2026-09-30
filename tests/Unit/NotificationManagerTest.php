<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Marrow\Database\Connection;
use Marrow\Mail\Mailer;
use Marrow\Notifications\NotificationManager;
use Marrow\Tests\Unit\Fixtures\CustomChannelNotification;
use Marrow\Tests\Unit\Fixtures\DatabaseOnlyNotification;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport\NullTransport;

// ── Tests ─────────────────────────────────────────────────────────────────────

beforeEach(function () {
    $this->conn = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
    $this->conn->statement('
        CREATE TABLE notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            type TEXT,
            notifiable_type TEXT,
            notifiable_id TEXT,
            data TEXT,
            read_at TEXT,
            created_at INTEGER
        )
    ');
    $this->manager = new NotificationManager(new Mailer(new SymfonyMailer(new NullTransport())), $this->conn);
});

test('send() persists a database notification with the expected columns', function () {
    $user = (object) ['id' => 42];
    $this->manager->send($user, new DatabaseOnlyNotification());

    $row = $this->conn->selectOne('SELECT * FROM notifications WHERE notifiable_id = ?', [42]);
    expect($row)->not->toBeNull();
    expect($row['type'])->toBe(DatabaseOnlyNotification::class);
    expect(json_decode($row['data'], true))->toBe(['message' => 'You have a new notification']);
});

test('send() accepts an iterable of notifiables', function () {
    $users = [(object) ['id' => 1], (object) ['id' => 2]];
    $this->manager->send($users, new DatabaseOnlyNotification());

    $count = $this->conn->selectOne('SELECT COUNT(*) as c FROM notifications');
    expect((int) $count['c'])->toBe(2);
});

test('a custom channel registered via extend() is invoked', function () {
    $received = null;
    $this->manager->extend('sms', function ($notifiable, $notification) use (&$received) {
        $received = $notifiable;
    });

    $user = (object) ['id' => 7];
    $this->manager->send($user, new CustomChannelNotification('sms'));

    expect($received)->toBe($user);
});

test('an unregistered custom channel is silently skipped rather than throwing', function () {
    $user = (object) ['id' => 7];

    // 'carrier-pigeon' was never registered via extend() — documented current
    // behavior is a silent no-op, not an exception.
    $this->manager->send($user, new CustomChannelNotification('carrier-pigeon'));

    expect(true)->toBeTrue(); // reaching here means it didn't throw
});
