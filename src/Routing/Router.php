<?php

declare(strict_types=1);

namespace Marrow\Routing;

use Marrow\Container;
use Marrow\Exceptions\HttpException;
use Marrow\Http\FormRequest;
use Marrow\Http\Request;
use Marrow\Middleware\MiddlewareResolver;
use Marrow\Middleware\Pipeline;
use Marrow\Routing\Attributes\Route as RouteAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fluent HTTP router with groups, resource routes, named routes,
 * middleware aliases, and controller method DI injection.
 */
class Router
{
    private RouteCollection $routes;

    /** The route matched by the current dispatch(), if any. */
    private ?Route $currentRoute = null;

    /** Group attribute stack (prefix, middleware, namespace) */
    private array $groupStack = [];

    /** @var array<string, string> Middleware alias → class name */
    private array $middlewareAliases = [];

    /** @var array<string, array<string>> Middleware group → list of references */
    private array $middlewareGroups = [];

    public function __construct(private readonly Container $container)
    {
        $this->routes = new RouteCollection();
    }

    // ─────────────────────── HTTP verb helpers ───────────────────────

    public function get(string $uri, mixed $action): Route
    {
        return $this->addRoute('GET', $uri, $action);
    }

    public function post(string $uri, mixed $action): Route
    {
        return $this->addRoute('POST', $uri, $action);
    }

    public function put(string $uri, mixed $action): Route
    {
        return $this->addRoute('PUT', $uri, $action);
    }

    public function patch(string $uri, mixed $action): Route
    {
        return $this->addRoute('PATCH', $uri, $action);
    }

    public function delete(string $uri, mixed $action): Route
    {
        return $this->addRoute('DELETE', $uri, $action);
    }

    public function any(string $uri, mixed $action): void
    {
        $this->match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], $uri, $action);
    }

    public function match(array $methods, string $uri, mixed $action): void
    {
        foreach ($methods as $method) {
            $this->addRoute($method, $uri, $action);
        }
    }

    // ─────────────────────── Groups ──────────────────────────────────

    public function group(array $attributes, callable $callback): void
    {
        $this->groupStack[] = $attributes;
        $callback($this);
        array_pop($this->groupStack);
    }

    // ─────────────────────── Resource routes ─────────────────────────

    public function resource(string $name, string $controller): void
    {
        $segment = basename($name);
        $singular = str_ends_with($segment, 's') ? substr($segment, 0, -1) : $segment;
        $prefix = '/' . ltrim($name, '/');

        $this->get("{$prefix}", [$controller, 'index'])->name("{$name}.index");
        $this->get("{$prefix}/create", [$controller, 'create'])->name("{$name}.create");
        $this->post("{$prefix}", [$controller, 'store'])->name("{$name}.store");
        $this->get("{$prefix}/{{$singular}}", [$controller, 'show'])->name("{$name}.show");
        $this->get("{$prefix}/{{$singular}}/edit", [$controller, 'edit'])->name("{$name}.edit");
        $this->put("{$prefix}/{{$singular}}", [$controller, 'update'])->name("{$name}.update");
        $this->patch("{$prefix}/{{$singular}}", [$controller, 'update']);
        $this->delete("{$prefix}/{{$singular}}", [$controller, 'destroy'])->name("{$name}.destroy");
    }

    // ─────────────────────── Attribute routing ────────────────────────

    /**
     * Registers every #[Route] attribute found on $class's public methods —
     * an alternative to writing $router->get(...) by hand for each action.
     * Still called explicitly from a module's routes.php, same as any other
     * route: this changes where a route's metadata lives, not Marrow's
     * routing-is-module-only convention. Honors the current group()
     * prefix/middleware exactly like get()/post()/etc., since it goes
     * through the same addRoute().
     *
     * A class-level #[Route] is repeatable — a controller with more than
     * one is registered once per class attribute (every method under each
     * prefix/middleware combination), not just the first. A route name
     * reused across more than one of those registrations resolves to
     * whichever was added last, same as calling ->name() twice anywhere
     * else in the router — avoid combining a repeated class-level #[Route]
     * with a fixed method-level `name:`.
     */
    public function controller(string $class): void
    {
        $reflection = new ReflectionClass($class);
        $classAttributes = $reflection->getAttributes(RouteAttribute::class);

        if ($classAttributes === []) {
            $this->registerControllerMethods($reflection, '', []);
            return;
        }

        foreach ($classAttributes as $classAttribute) {
            $classRoute = $classAttribute->newInstance();
            $this->registerControllerMethods(
                $reflection,
                rtrim($classRoute->uri, '/'),
                (array) $classRoute->middleware
            );
        }
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @param string[] $sharedMiddleware
     */
    private function registerControllerMethods(ReflectionClass $reflection, string $prefix, array $sharedMiddleware): void
    {
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(RouteAttribute::class) as $attribute) {
                $routeAttr = $attribute->newInstance();
                $uri = $prefix . '/' . ltrim($routeAttr->uri, '/');
                $middleware = array_merge($sharedMiddleware, (array) $routeAttr->middleware);

                foreach ((array) $routeAttr->method as $httpMethod) {
                    $route = $this->addRoute(strtoupper($httpMethod), $uri, [$reflection->getName(), $method->getName()]);

                    if ($middleware !== []) {
                        $route->middleware($middleware);
                    }
                    if ($routeAttr->name !== null) {
                        $route->name($routeAttr->name);
                    }
                }
            }
        }
    }

    // ─────────────────────── URL generation ──────────────────────────

    /** @throws \RuntimeException If no route is registered under $name. */
    public function route(string $name, array $params = []): string
    {
        $route = $this->routes->getByName($name);

        if ($route === null) {
            throw new \RuntimeException("No route named [{$name}] found.");
        }

        return $route->generateUrl($params);
    }

    // ─────────────────────── Dispatching ────────────────────────────

    /**
     * @throws HttpException 404/405 if no route matches, via RouteCollection::match().
     * @throws \RuntimeException If the matched route's action isn't callable, or a
     *                          required controller method parameter can't be resolved.
     */
    public function dispatch(Request $request): Response
    {
        [$route, $params] = $this->routes->match($request->getMethod(), $request->getPathInfo());
        $this->currentRoute = $route;
        $request->setRouteParams($params);

        $middlewares = $this->resolveMiddlewares($route->getMiddlewares());

        $pipeline = new Pipeline($this->container);

        return $pipeline
            ->send($request)
            ->through($middlewares)
            ->then(fn(Request $req) => $this->callAction($route->getAction(), $req, $params));
    }

    // ─────────────────────── Utilities ──────────────────────────────

    public function getRoutes(): RouteCollection
    {
        return $this->routes;
    }

    /** The route matched by the most recent dispatch() call on this Router, if any. */
    public function getCurrentRoute(): ?Route
    {
        return $this->currentRoute;
    }

    public function setMiddlewareAliases(array $aliases): void
    {
        $this->middlewareAliases = $aliases;
    }

    public function setMiddlewareGroups(array $groups): void
    {
        $this->middlewareGroups = $groups;
    }

    public function loadRoutesFrom(string $file): void
    {
        $router = $this;
        require $file;
    }

    // ─────────────────────── Private ────────────────────────────────

    private function addRoute(string $method, string $uri, mixed $action): Route
    {
        $uri = $this->applyGroupPrefix($uri);
        $action = $this->applyGroupNamespace($action);

        $route = new Route($method, $uri, $action);

        // Apply group middlewares
        foreach ($this->groupStack as $group) {
            if (!empty($group['middleware'])) {
                $route->middleware($group['middleware']);
            }
        }

        $this->routes->add($route);
        return $route;
    }

    private function applyGroupPrefix(string $uri): string
    {
        $prefix = '';
        foreach ($this->groupStack as $group) {
            if (!empty($group['prefix'])) {
                $prefix .= '/' . ltrim($group['prefix'], '/');
            }
        }
        return $prefix . '/' . ltrim($uri, '/');
    }

    private function applyGroupNamespace(mixed $action): mixed
    {
        if (!is_array($action) || !isset($action[0])) {
            return $action;
        }
        foreach (array_reverse($this->groupStack) as $group) {
            if (!empty($group['namespace'])) {
                $action[0] = rtrim($group['namespace'], '\\') . '\\' . ltrim($action[0], '\\');
                break;
            }
        }
        return $action;
    }

    private function resolveMiddlewares(array $middlewares): array
    {
        // Use the same resolver as the HTTP Kernel so route, group, and global
        // middleware all expand groups, map aliases, and preserve ":params"
        // identically. Aliases that point to groups, and groups nested inside
        // groups, are handled recursively.
        return (new MiddlewareResolver($this->middlewareAliases, $this->middlewareGroups))
            ->resolve($middlewares);
    }

    private function callAction(mixed $action, Request $request, array $params): Response
    {
        if (is_callable($action)) {
            $result = $action($request, ...$params);
            return $this->toResponse($result);
        }

        if (is_array($action)) {
            [$controllerClass, $method] = $action;
            $controller = $this->container->make($controllerClass);
            $args = $this->resolveMethodParams(
                new ReflectionMethod($controller, $method),
                $request,
                $params
            );
            $result = $controller->$method(...$args);
            return $this->toResponse($result);
        }

        if (is_string($action) && str_contains($action, '@')) {
            [$controllerClass, $method] = explode('@', $action, 2);
            return $this->callAction([$controllerClass, $method], $request, $params);
        }

        throw new \RuntimeException('Invalid route action.');
    }

    private function resolveMethodParams(ReflectionMethod $method, Request $request, array $routeParams): array
    {
        $args = [];
        foreach ($method->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $typeName = $type->getName();

                if (is_a($typeName, FormRequest::class, true)) {
                    // Auto-validate FormRequest subclass before injecting
                    $formRequest = $typeName::createFrom($request);
                    $formRequest->validateResolved();
                    $args[] = $formRequest;
                } elseif ($typeName === Request::class || is_subclass_of($typeName, Request::class)) {
                    $args[] = $request;
                } else {
                    $args[] = $this->container->make($typeName);
                }
            } elseif (\array_key_exists($param->getName(), $routeParams)) {
                $args[] = $routeParams[$param->getName()];
            } elseif ($param->isOptional()) {
                $args[] = $param->getDefaultValue();
            } elseif ($param->allowsNull()) {
                $args[] = null;
            } else {
                throw new \RuntimeException(sprintf(
                    'Unable to resolve required parameter [$%s] for controller method %s::%s().',
                    $param->getName(),
                    $method->getDeclaringClass()->getName(),
                    $method->getName()
                ));
            }
        }
        return $args;
    }

    private function toResponse(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }
        if (is_array($result) || is_object($result)) {
            return new \Marrow\Http\JsonResponse($result);
        }
        return new \Marrow\Http\Response((string) $result, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
