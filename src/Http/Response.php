<?php

declare(strict_types=1);

namespace Marrow\Http;

use Marrow\Application;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * HTTP Response — composes a Symfony Response internally instead of
 * extending it, specifically so `Marrow\Http\RedirectResponse` and
 * `Marrow\Http\JsonResponse` can be genuine *subclasses of this class*
 * rather than siblings of it.
 *
 * Before this: `Marrow\Http\Response extends Symfony\...\Response` and
 * `Marrow\Http\RedirectResponse extends Symfony\...\RedirectResponse`
 * directly — two different Symfony leaf classes, meaning
 * `RedirectResponse` was never an `instanceof Response` even though both
 * were "a Marrow response" in every way that mattered to application code.
 * A controller method that could return either a rendered view and a
 * redirect had no single Marrow type to declare — only their shared
 * Symfony ancestor worked, forcing every such method (and this framework's
 * own Router/Pipeline internals) to type against
 * `Symfony\Component\HttpFoundation\Response` instead of this class. See
 * `marrow/warden`'s controllers (pre-this-change) for the exact workaround.
 *
 * Every Symfony Response method not explicitly overridden below (e.g.
 * `setContent()`, `getStatusCode()`, `isRedirect()`, ...) still works via
 * `__call()`, forwarded to the wrapped instance — `@mixin` below is what
 * gives IDEs/PHPStan full autocomplete and type-checking for those without
 * hand-writing a forwarding method for each one. `headers` is a real public
 * property (not magic), assigned once to the *same* `ResponseHeaderBag`
 * object the wrapped response reads from when sending — mutating it here
 * mutates what actually gets sent, with no extra plumbing needed.
 *
 * @mixin SymfonyResponse
 */
class Response
{
    public ResponseHeaderBag $headers;

    protected SymfonyResponse $response;

    public function __construct(?string $content = '', int $status = 200, array $headers = [])
    {
        $this->response = new SymfonyResponse($content, $status, $headers);
        $this->headers = $this->response->headers;
    }

    /** The real Symfony Response underneath — escape hatch for anything genuinely Symfony-specific. */
    public function toSymfonyResponse(): SymfonyResponse
    {
        return $this->response;
    }

    /**
     * Forwards to the wrapped Symfony Response. A fluent method there
     * (`setStatusCode()`, `setContent()`, ...) returns that instance itself
     * for chaining — substituted with `$this` here so chaining continues on
     * *this* wrapper, not a leaked reference to the inner Symfony object.
     */
    public function __call(string $name, array $arguments): mixed
    {
        $result = $this->response->{$name}(...$arguments);
        return $result === $this->response ? $this : $result;
    }

    public function __toString(): string
    {
        return (string) $this->response;
    }

    /** Deep-clones the wrapped response too, so `headers` stays in sync with it rather than aliasing the original's. */
    public function __clone(): void
    {
        $this->response = clone $this->response;
        $this->headers = $this->response->headers;
    }

    public function send(bool $flush = true): static
    {
        $this->response->send($flush);
        return $this;
    }

    public function prepare(Request $request): static
    {
        $this->response->prepare($request);
        return $this;
    }

    // ── Static factories (unchanged from before this class composed Symfony) ──

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

    public static function render(string $template, array $data = [], int $status = 200, array $headers = []): self
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
