<?php

declare(strict_types=1);

namespace Marrow\Http;

use Marrow\Container;
use Marrow\Exceptions\Handler as ExceptionHandler;
use Marrow\Exceptions\HttpException;
use Marrow\Middleware\MiddlewareResolver;
use Marrow\Middleware\Pipeline;
use Marrow\Routing\Router;
use Marrow\Session\SessionManager;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * HTTP Kernel: validates configuration, applies global middlewares,
 * dispatches to the Router, and delegates errors to the ExceptionHandler.
 */
class Kernel
{
    public function __construct(
        private readonly Container $container,
        private readonly Router $router,
        private readonly array $middlewareConfig,
        private readonly ExceptionHandler $exceptionHandler
    ) {
    }

    public function handle(Request $request): SymfonyResponse
    {
        try {
            // Bind the active request into the container so request-scoped
            // services (e.g. the JWT guard) can resolve it.
            $this->container->instance(Request::class, $request);

            $this->validateAppKey($request);
            $this->bootSession($request);

            $aliases = $this->middlewareConfig['aliases'] ?? [];
            $groups  = $this->middlewareConfig['groups']  ?? [];

            // One resolver, shared semantics: the Router resolves route/group
            // middleware exactly the way the Kernel resolves the global stack.
            $resolver = new MiddlewareResolver($aliases, $groups);
            $this->router->setMiddlewareAliases($aliases);
            $this->router->setMiddlewareGroups($groups);

            $globalMiddlewares = $resolver->resolve($this->middlewareConfig['global'] ?? []);

            // Prepend hot-reload middleware in local/dev mode (handles /__marrow/ping)
            if ($this->isDevMode()) {
                array_unshift($globalMiddlewares, \Marrow\Middleware\HotReloadMiddleware::class);
            }

            $pipeline = new Pipeline($this->container);

            return $pipeline
                ->send($request)
                ->through($globalMiddlewares)
                ->then(fn(Request $req) => $this->router->dispatch($req));

        } catch (Throwable $e) {
            return $this->exceptionHandler->render($request, $e);
        }
    }

    /**
     * Fail fast if APP_KEY is missing — every request needs it for CSRF and sessions.
     * Console commands bypass this check so that `php forge key:generate` still works.
     * Caught by handle()'s own catch-all and rendered via the exception handler,
     * so this never bubbles out of the framework as an uncaught error.
     *
     * @throws \RuntimeException If APP_KEY is unset or empty.
     */
    private function validateAppKey(Request $request): void
    {
        $key = trim((string) ($_ENV['APP_KEY'] ?? ''));
        if ($key !== '') {
            return;
        }

        throw new \RuntimeException(
            'Application key is not set.' . PHP_EOL .
            'Generate one with:  php forge key:generate' . PHP_EOL .
            'Or copy your env:   cp .env.example .env'
        );
    }

    private function isDevMode(): bool
    {
        $env   = strtolower((string) ($_ENV['APP_ENV'] ?? 'production'));
        $debug = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);
        return $env === 'local' || $debug;
    }

    private function bootSession(Request $request): void
    {
        if ($this->container->has(SessionManager::class)) {
            $session = $this->container->make(SessionManager::class);
            $session->start($request);
        }
    }
}
