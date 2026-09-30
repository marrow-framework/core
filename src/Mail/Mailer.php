<?php

declare(strict_types=1);

namespace Marrow\Mail;

use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

/**
 * Mailer — a thin wrapper around symfony/mailer.
 *
 * Configure via config/mail.php (DSN built from MAIL_* env vars):
 *   'dsn' => env('MAIL_DSN', 'smtp://localhost:1025')
 *
 * Usage (inject Mailer via constructor):
 *   $mailer->to('jane@example.com')
 *       ->subject('Welcome')
 *       ->view('@blog/emails/welcome', ['user' => $user])
 *       ->send();
 *
 *   // Or send a Mailable object:
 *   $mailer->send(new WelcomeMail($user));
 */
class Mailer
{
    public function __construct(
        private readonly MailerInterface $transport,
        private readonly array $config = []
    ) {
    }

    public static function fromDsn(string $dsn, array $config = []): self
    {
        $transport = Transport::fromDsn($dsn);
        return new self(new SymfonyMailer($transport), $config);
    }

    /** Begin composing a message addressed to one or more recipients. */
    public function to(string|array $recipients): PendingMail
    {
        return (new PendingMail($this))->to($recipients);
    }

    /** Send a fully-built Mailable or raw Email. */
    public function send(Mailable|Email $message): void
    {
        if ($message instanceof Mailable) {
            $message = $message->build($this->config);
        }

        $from = $this->config['from'] ?? null;
        if ($from && !$message->getFrom()) {
            $message->from($from['address'] ?? $from);
        }

        $this->transport->send($message);
    }
}
