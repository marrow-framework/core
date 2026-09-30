<?php

declare(strict_types=1);

namespace Marrow\Mail;

use Marrow\Application;
use Marrow\Template\Engine as TemplateEngine;
use Symfony\Component\Mime\Email;

/**
 * Fluent builder for a single outgoing message.
 * Returned by Mailer::to().
 *
 * view() resolves TemplateEngine ambiently via Application::getInstance() —
 * PendingMail is built by application code (`$mailer->to(...)->view(...)`),
 * not resolved through the Container, so there's no constructor to inject
 * into. Same accepted-escape-hatch category as Mailable::renderView().
 *
 * Config-driven defaults (e.g. the `from` address) are applied by
 * Mailer::send() itself once this message reaches it — not here — so this
 * class has no need for its own copy of that config.
 */
class PendingMail
{
    private array $to = [];
    private array $cc = [];
    private array $bcc = [];
    private ?string $subject = null;
    private ?string $html = null;
    private ?string $text = null;
    private array $attachments = [];
    private ?string $replyTo = null;

    public function __construct(
        private readonly Mailer $mailer
    ) {
    }

    public function to(string|array $recipients): self
    {
        $this->to = array_merge($this->to, (array) $recipients);
        return $this;
    }

    public function cc(string|array $recipients): self
    {
        $this->cc = array_merge($this->cc, (array) $recipients);
        return $this;
    }

    public function bcc(string|array $recipients): self
    {
        $this->bcc = array_merge($this->bcc, (array) $recipients);
        return $this;
    }

    public function replyTo(string $address): self
    {
        $this->replyTo = $address;
        return $this;
    }

    public function subject(string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    /** Render a Twig template as the HTML body. */
    public function view(string $template, array $data = []): self
    {
        $engine = Application::getInstance()->getContainer()->make(TemplateEngine::class);
        $this->html = $engine->render($template, $data);
        return $this;
    }

    public function html(string $html): self
    {
        $this->html = $html;
        return $this;
    }

    public function text(string $text): self
    {
        $this->text = $text;
        return $this;
    }

    public function attach(string $path, ?string $name = null): self
    {
        $this->attachments[] = ['path' => $path, 'name' => $name];
        return $this;
    }

    public function send(): void
    {
        $email = new Email();

        foreach ($this->to as $addr) {
            $email->addTo($addr);
        }
        foreach ($this->cc as $addr) {
            $email->addCc($addr);
        }
        foreach ($this->bcc as $addr) {
            $email->addBcc($addr);
        }
        if ($this->replyTo) {
            $email->replyTo($this->replyTo);
        }
        if ($this->subject !== null) {
            $email->subject($this->subject);
        }
        if ($this->html !== null) {
            $email->html($this->html);
        }
        if ($this->text !== null) {
            $email->text($this->text);
        }
        foreach ($this->attachments as $att) {
            $email->attachFromPath($att['path'], $att['name']);
        }

        $this->mailer->send($email);
    }
}
