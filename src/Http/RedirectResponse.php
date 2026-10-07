<?php

declare(strict_types=1);

namespace Marrow\Http;

use Marrow\Application;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

/**
 * Redirect response with named-route support and back() helper.
 *
 * A genuine subclass of `Response` (not a Symfony-RedirectResponse sibling
 * of it — see Response's own docblock) — composes a
 * `Symfony\Component\HttpFoundation\RedirectResponse` internally instead of
 * `Response`'s plain base one, so `getTargetUrl()`/`setTargetUrl()` (and
 * everything else specific to a redirect) still work via the inherited
 * `__call()` forwarding, with no need to redeclare them here.
 *
 * Inside a Controller, prefer `$this->redirectToRoute(...)` — it uses the
 * Controller's own constructor-injected Router. route() below resolves the
 * Router ambiently via Application::getInstance() and exists for contexts
 * with no DI available at all.
 *
 * @mixin SymfonyRedirect
 */
class RedirectResponse extends Response
{
    private array $flashData = [];

    public function __construct(string $url = '', int $status = 302, array $headers = [])
    {
        // Deliberately not parent::__construct() — that builds a plain
        // Symfony Response, not a Symfony RedirectResponse.
        $this->response = new SymfonyRedirect($url ?: '/', $status, $headers);
        $this->headers = $this->response->headers;
    }

    /**
     * Redirect to a named route.
     *
     * setTargetUrl() goes through $this (not $this->response) deliberately:
     * the inherited $response property stays typed as the *base* Symfony
     * Response (PHP property types are invariant — a subclass cannot
     * re-type an inherited property, even to a covariant one, which a
     * SymfonyRedirect-typed override here would be), so calling
     * setTargetUrl() on it directly wouldn't type-check. Routing through
     * $this uses Response's inherited __call() forwarding instead, which is
     * exactly what @mixin SymfonyRedirect above documents as the escape
     * hatch for everything redirect-specific this class doesn't redeclare.
     */
    public function route(string $name, array $params = []): static
    {
        $url = Application::getInstance()->getContainer()->make(\Marrow\Routing\Router::class)->route($name, $params);
        $this->setTargetUrl($url);
        return $this;
    }

    /** Redirect back to the previous URL. */
    public function back(): static
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '/';
        $this->setTargetUrl($referer);
        return $this;
    }

    /** Flash data into the session before redirecting. */
    public function with(string $key, mixed $value): static
    {
        $this->flashData[$key] = $value;
        return $this;
    }

    /** Flash validation errors and old input to session. */
    public function withErrors(array $errors, string $bag = 'default'): static
    {
        $this->flashData['_errors'] = $errors;
        return $this;
    }

    public function withInput(array $input = []): static
    {
        $this->flashData['_old_input'] = $input;
        return $this;
    }

    public function getFlashData(): array
    {
        return $this->flashData;
    }
}
