<?php

declare(strict_types=1);

namespace Marrow\Mail;

use Marrow\Application;
use Marrow\Template\Engine as TemplateEngine;
use Symfony\Component\Mime\Email;

/**
 * Base class for reusable, testable email messages.
 *
 * Mailable objects are instantiated directly by application code (`new
 * WelcomeMail($user)`), not resolved through the Container — so renderView()
 * resolves TemplateEngine ambiently via Application::getInstance() rather
 * than through constructor injection. Same category as Response::view()/
 * RedirectResponse::route(): an accepted escape hatch for a context with no
 * natural DI entry point, not an oversight.
 *
 * Subclass and implement build():
 *
 *   class WelcomeMail extends Mailable
 *   {
 *       public function __construct(private User $user) {}
 *
 *       public function build(array $config = []): Email
 *       {
 *           return $this->makeEmail()
 *               ->to($this->user->email)
 *               ->subject('Welcome to Marrow')
 *               ->html($this->renderView('@common/emails/welcome', ['user' => $this->user]));
 *       }
 *   }
 */
abstract class Mailable
{
    abstract public function build(array $config = []): Email;

    protected function makeEmail(): Email
    {
        return new Email();
    }

    protected function renderView(string $template, array $data = []): string
    {
        $engine = Application::getInstance()->getContainer()->make(TemplateEngine::class);
        return $engine->render($template, $data);
    }
}
