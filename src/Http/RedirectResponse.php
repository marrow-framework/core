<?php

declare(strict_types=1);

namespace Marrow\Http;

use Marrow\Application;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

/**
 * Redirect response with named-route support and back() helper.
 *
 * Inside a Controller, prefer `$this->redirectToRoute(...)` — it uses the
 * Controller's own constructor-injected Router. route() below resolves the
 * Router ambiently via Application::getInstance() and exists for contexts
 * with no DI available at all.
 */
class RedirectResponse extends SymfonyRedirect
{
    private array $flashData = [];

    public function __construct(string $url = '', int $status = 302, array $headers = [])
    {
        parent::__construct($url ?: '/', $status, $headers);
    }

    /** Redirect to a named route. */
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
