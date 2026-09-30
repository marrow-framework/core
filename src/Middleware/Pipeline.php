<?php

declare(strict_types=1);

namespace Marrow\Middleware;

use Closure;
use Marrow\Container;
use Marrow\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware pipeline — sends a Request through an ordered stack of middlewares
 * then calls the final destination handler.
 *
 * Two middleware styles are supported:
 *
 *  Onion-style (PSR-15 inspired):
 *    handle(Request $req, callable $next, ...$params): Response
 *    Full control — wrap $next(...) in your own try/catch if you need to
 *    react to exceptions raised further down the chain.
 *
 *  Hook-style (Django-inspired):
 *    processRequest(Request $req, ...$params): ?Response   — early-return skips the chain
 *    processResponse(Request $req, Response $res, ...$params): Response
 *    processException(Request $req, \Throwable $e, ...$params): ?Response
 *      — called when something further down the chain (an inner middleware or
 *        the destination/controller) throws. Return a Response to recover;
 *        return null to let the exception keep propagating outward, giving
 *        the next enclosing middleware (or the global ExceptionHandler) a
 *        chance. A recovered response still passes through this middleware's
 *        own processResponse(), exactly like a normal response would.
 *
 * Pipe syntax:
 *   'App\Middleware\Foo'          — FQCN, no parameters
 *   'App\Middleware\Foo:a,b'      — FQCN with comma-separated parameters
 *   $objectInstance               — pre-instantiated middleware object
 */
class Pipeline
{
    private Request $passable;
    private array   $pipes = [];

    public function __construct(private readonly Container $container)
    {
    }

    public function send(Request $request): static
    {
        $this->passable = $request;
        return $this;
    }

    public function through(array $middlewares): static
    {
        $this->pipes = $middlewares;
        return $this;
    }

    /**
     * @throws \InvalidArgumentException If a pipe entry is neither an object nor a class-name string.
     * @throws \RuntimeException If a resolved middleware defines neither handle() nor any process*() hook.
     */
    public function then(callable $destination): Response
    {
        $pipeline = array_reduce(
            array_reverse($this->pipes),
            $this->carry(),
            fn(Request $req): Response => $destination($req)
        );

        return $pipeline($this->passable);
    }

    private function carry(): Closure
    {
        return function (Closure $next, mixed $pipe): Closure {
            return function (Request $request) use ($next, $pipe): Response {
                [$middleware, $params] = $this->resolve($pipe);

                $hasProcessRequest   = method_exists($middleware, 'processRequest');
                $hasProcessResponse  = method_exists($middleware, 'processResponse');
                $hasProcessException = method_exists($middleware, 'processException');

                // Hook-style: any of processRequest / processResponse / processException.
                if ($hasProcessRequest || $hasProcessResponse || $hasProcessException) {
                    if ($hasProcessRequest) {
                        $early = $middleware->processRequest($request, ...$params);
                        if ($early instanceof Response) {
                            return $early;
                        }
                    }

                    try {
                        $response = $next($request);
                    } catch (\Throwable $e) {
                        if (!$hasProcessException) {
                            throw $e;
                        }
                        $recovered = $middleware->processException($request, $e, ...$params);
                        if (!$recovered instanceof Response) {
                            throw $e;
                        }
                        $response = $recovered;
                    }

                    if ($hasProcessResponse) {
                        $response = $middleware->processResponse($request, $response, ...$params);
                    }

                    return $response;
                }

                // Onion-style: handle($request, $next, ...$params)
                if (method_exists($middleware, 'handle')) {
                    return $middleware->handle($request, $next, ...$params);
                }

                throw new \RuntimeException(sprintf(
                    'Middleware [%s] must define handle($request, $next) or '
                    . 'processRequest()/processResponse().',
                    get_debug_type($middleware)
                ));
            };
        };
    }

    /**
     * Resolve a pipe entry to [object, params[]].
     *
     * Accepted forms:
     *   - object instance  → used directly
     *   - 'FQCN'           → resolved via container, no params
     *   - 'FQCN:a,b'       → resolved via container, params = ['a', 'b']
     */
    private function resolve(mixed $pipe): array
    {
        if (is_object($pipe)) {
            return [$pipe, []];
        }

        if (!is_string($pipe)) {
            throw new \InvalidArgumentException(
                'Middleware must be a class name string or an object instance, got ' . gettype($pipe)
            );
        }

        $params = [];
        if (str_contains($pipe, ':')) {
            [$pipe, $paramStr] = explode(':', $pipe, 2);
            $params = array_map('trim', explode(',', $paramStr));
        }

        return [$this->container->make($pipe), $params];
    }
}
