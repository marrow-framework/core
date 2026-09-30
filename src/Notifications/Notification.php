<?php

declare(strict_types=1);

namespace Marrow\Notifications;

/**
 * Base class for notifications that can be delivered across multiple channels.
 *
 *   class InvoicePaid extends Notification
 *   {
 *       public function __construct(private Invoice $invoice) {}
 *
 *       public function via(object $notifiable): array
 *       {
 *           return ['mail', 'database'];
 *       }
 *
 *       public function toMail(object $notifiable, Mailer $mailer): PendingMail
 *       {
 *           return $mailer->to($notifiable->email)
 *               ->subject('Invoice paid')
 *               ->view('@billing/mail/paid', ['invoice' => $this->invoice]);
 *       }
 *
 *       public function toDatabase(object $notifiable): array
 *       {
 *           return ['invoice_id' => $this->invoice->id, 'amount' => $this->invoice->total];
 *       }
 *   }
 */
abstract class Notification
{
    /**
     * Channels this notification should be delivered on for the given
     * notifiable. Supported: 'mail', 'database'.
     *
     * @return string[]
     */
    abstract public function via(object $notifiable): array;
}
