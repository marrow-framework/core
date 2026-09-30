<?php

declare(strict_types=1);

namespace Marrow\Notifications;

use Marrow\Database\Connection;
use Marrow\Mail\Mailer;
use Marrow\Mail\PendingMail;

/**
 * Dispatches notifications across their declared channels.
 *
 * Built-in channels:
 *   mail     → uses the Mailer (notification must implement toMail()).
 *   database → inserts a row into the `notifications` table
 *              (notification must implement toDatabase()).
 *
 * Register custom channels with extend('sms', fn($notifiable, $n) => ...).
 *
 * Usage:
 *   $notifications->send($user, new InvoicePaid($invoice));
 *   $notifications->sendNow($user, $notification);   // alias
 */
class NotificationManager
{
    /** @var array<string, callable> Custom channel handlers. */
    private array $channels = [];

    public function __construct(
        private readonly Mailer $mailer,
        private readonly Connection $db,
        private readonly string $table = 'notifications'
    ) {
    }

    public function extend(string $channel, callable $handler): void
    {
        $this->channels[$channel] = $handler;
    }

    /** Send to a single notifiable or an iterable of them. */
    public function send(object|iterable $notifiables, Notification $notification): void
    {
        $list = is_iterable($notifiables) ? $notifiables : [$notifiables];

        foreach ($list as $notifiable) {
            foreach ($notification->via($notifiable) as $channel) {
                $this->deliver($channel, $notifiable, $notification);
            }
        }
    }

    public function sendNow(object|iterable $notifiables, Notification $notification): void
    {
        $this->send($notifiables, $notification);
    }

    private function deliver(string $channel, object $notifiable, Notification $notification): void
    {
        match ($channel) {
            'mail'     => $this->toMail($notifiable, $notification),
            'database' => $this->toDatabase($notifiable, $notification),
            default    => $this->toCustom($channel, $notifiable, $notification),
        };
    }

    private function toMail(object $notifiable, Notification $notification): void
    {
        if (!method_exists($notification, 'toMail')) {
            return;
        }
        $message = $notification->toMail($notifiable, $this->mailer);
        if ($message instanceof PendingMail) {
            $message->send();
        }
    }

    private function toDatabase(object $notifiable, Notification $notification): void
    {
        if (!method_exists($notification, 'toDatabase')) {
            return;
        }

        $data = $notification->toDatabase($notifiable);

        $this->db->insert($this->table, [
            'type'            => $notification::class,
            'notifiable_type' => $notifiable::class,
            'notifiable_id'   => method_exists($notifiable, 'getKey') ? $notifiable->getKey() : ($notifiable->id ?? null),
            'data'            => json_encode($data, JSON_THROW_ON_ERROR),
            'read_at'         => null,
            'created_at'      => time(),
        ]);
    }

    private function toCustom(string $channel, object $notifiable, Notification $notification): void
    {
        if (isset($this->channels[$channel])) {
            ($this->channels[$channel])($notifiable, $notification);
        }
    }
}
