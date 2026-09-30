<?php

declare(strict_types=1);

namespace Marrow\Http;

use Marrow\Application;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * HTTP Response wrapper with convenient static factories.
 *
 * Inside a Controller, prefer the injected instance helpers (`$this->view(...)`)
 * — they use the Controller's own constructor-injected TemplateEngine. These
 * static factories resolve services ambiently via Application::getInstance()
 * and exist for contexts with no DI available at all (closures, CLI output).
 */
class Response extends SymfonyResponse
{
    /** Render a Twig template and return a Response. */
    public static function view(string $template, array $data = [], int $status = 200): self
    {
        $engine = Application::getInstance()->getContainer()->make(\Marrow\Template\Engine::class);
        $html = $engine->render($template, $data);
        return new self($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /** Return a JSON response. */
    public static function json(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, $headers);
    }

    /** Start a redirect chain. */
    public static function redirect(string $url = '', int $status = 302): RedirectResponse
    {
        return new RedirectResponse($url, $status);
    }

    public static function render(string $template, array $data = [], int $status = 200, array $headers = []): SymfonyResponse
    {
        $engine = Application::getInstance()->getContainer()->make(\Marrow\Template\Engine::class);
        $html = $engine->render($template, $data);
        return new self($html, $status, array_merge(['Content-Type' => 'text/html; charset=UTF-8'], $headers));
    }

    /** Return a plain text response. */
    public static function text(string $content, int $status = 200): self
    {
        return new self($content, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public static function noContent(): self
    {
        return new self('', 204);
    }
}
