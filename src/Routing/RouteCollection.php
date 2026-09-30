<?php

declare(strict_types=1);

namespace Marrow\Routing;

use Marrow\Exceptions\HttpException;

/**
 * Holds all registered routes and finds the best match for a request.
 */
class RouteCollection
{
    /** @var array<string, Route[]> Method => routes */
    private array $routes = [];

    /** @var array<string, Route> Name => route */
    private array $named = [];

    public function add(Route $route): void
    {
        $method = strtoupper($route->getMethod());
        $this->routes[$method][] = $route;

        if ($name = $route->getName()) {
            $this->named[$name] = $route;
        }
    }

    /** Register a name on the last added route (used by Router::name()). */
    public function setName(string $method, string $name): void
    {
        $method = strtoupper($method);
        $routes = $this->routes[$method] ?? [];
        $last = end($routes);
        if ($last instanceof Route) {
            $last->name($name);
            $this->named[$name] = $last;
        }
    }

    /**
     * Match a request. Returns [Route, params] or throws HttpException.
     */
    /**
     * @throws HttpException 405 if $uri matches a route but not for $method,
     *                       404 if it matches no route at all.
     */
    public function match(string $method, string $uri): array
    {
        $method = strtoupper($method);

        // Try exact method first, then HEAD→GET fallback
        $candidates = $this->routes[$method] ?? [];
        if ($method === 'HEAD') {
            $candidates = array_merge($candidates, $this->routes['GET'] ?? []);
        }

        foreach ($candidates as $route) {
            $params = $route->match($uri);
            if ($params !== null) {
                return [$route, $params];
            }
        }

        // Check if any other method matches (to return 405 vs 404)
        foreach ($this->routes as $m => $routeList) {
            if ($m === $method) {
                continue;
            }
            foreach ($routeList as $route) {
                if ($route->match($uri) !== null) {
                    throw new HttpException(405, "Method {$method} not allowed.");
                }
            }
        }

        throw new HttpException(404, "No route found for [{$method}] {$uri}");
    }

    public function getByName(string $name): ?Route
    {
        if (isset($this->named[$name])) {
            return $this->named[$name];
        }

        // Lazy load names since Route::name() might be called after add()
        foreach ($this->routes as $routes) {
            foreach ($routes as $route) {
                if ($rName = $route->getName()) {
                    $this->named[$rName] = $route;
                }
            }
        }

        return $this->named[$name] ?? null;
    }

    public function all(): array
    {
        return $this->routes;
    }
}
