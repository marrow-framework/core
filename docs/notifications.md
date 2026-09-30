# Notifications

`Marrow\Notifications\Notification` — deliver a single message across one
or more channels without duplicating the "what happened" logic per channel.

## Defining a notification

```php
namespace App\Notifications;

use Marrow\Mail\Mailer;
use Marrow\Mail\PendingMail;
use Marrow\Notifications\Notification;

class InvoicePaid extends Notification
{
    public function __construct(private readonly Invoice $invoice) {}

    /** @return string[] Channels: 'mail', 'database', or a custom one registered via extend(). */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable, Mailer $mailer): PendingMail
    {
        return $mailer->to($notifiable->email)
            ->subject('Invoice paid')
            ->view('@billing/emails/paid', ['invoice' => $this->invoice]);
    }

    public function toDatabase(object $notifiable): array
    {
        return ['invoice_id' => $this->invoice->id, 'amount' => $this->invoice->total];
    }
}
```

Only implement `toMail()`/`toDatabase()` for the channels you list in
`via()` — `NotificationManager` checks `method_exists()` before calling
either.

## Sending

```php
$notifications = app(\Marrow\Notifications\NotificationManager::class);

$notifications->send($user, new InvoicePaid($invoice));
$notifications->send($users, new InvoicePaid($invoice));   // any iterable of notifiables
$notifications->sendNow($user, new InvoicePaid($invoice)); // alias, no queueing distinction exists
```

## The `database` channel

Writes to the `notifications` table (`config/notifications.php → 'table'`,
default `notifications`): `type` (the notification's FQCN),
`notifiable_type`, `notifiable_id` (via `getKey()` if the notifiable is a
`Model`, else its `id` property), `data` (JSON-encoded, from
`toDatabase()`), `read_at` (`null` initially — marking as read is left to
your application), `created_at` (Unix timestamp).

## Custom channels

```php
$notifications->extend('sms', function (object $notifiable, Notification $notification) {
    // e.g. call a Twilio HttpClient wrapper here
});
```

Then list `'sms'` in a notification's `via()`.

## Table

Created by the skeleton's migration; column types match
`NotificationManager` exactly — `created_at`/`read_at` are plain integer
Unix timestamps, not SQL `DATETIME`. See
[Migrations & Schema](migrations.md).
