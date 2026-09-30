<?php

declare(strict_types=1);

namespace Marrow\Routing;

/**
 * Represents a single route with its URI pattern, HTTP method,
 * action, name, middlewares, and constraints.
 */
class Route
{
    private ?string $name = null;
    private array $middlewares = [];
    private array $wheres = [];
    private string $compiledPattern = '';

    public function __construct(
        private readonly string $method,
        private readonly string $uri,
        private readonly mixed $action
    ) {
        $this->compiledPattern = $this->compile($uri);
    }

    public function name(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function middleware(string|array $middleware): static
    {
        $middlewares = is_array($middleware) ? $middleware : [$middleware];
        $this->middlewares = array_merge($this->middlewares, $middlewares);
        return $this;
    }

    /**
     * Shortcut for ->middleware('auth') / ->middleware('auth:guard') — see
     * Middleware\Authenticate. Chainable with everything else:
     *
     *   $router->get('/dashboard', [DashboardController::class, 'index'])
     *       ->name('dashboard')
     *       ->auth();
     *
     *   $router->get('/admin', [AdminController::class, 'index'])->auth('jwt');
     */
    public function auth(string $guard = 'session'): static
    {
        return $this->middleware($guard === 'session' ? 'auth' : "auth:{$guard}");
    }

    /**
     * Shortcut for ->middleware("throttle:{$maxAttempts},{$decayMinutes}") —
     * see Middleware\ThrottleRequests (sliding-window rate limiting).
     *
     *   $router->post('/login', [AuthController::class, 'login'])->throttle(5, 1);
     */
    public function throttle(int $maxAttempts = 60, int $decayMinutes = 1): static
    {
        return $this->middleware("throttle:{$maxAttempts},{$decayMinutes}");
    }

    public function where(string $param, string $regex): static
    {
        $this->wheres[$param] = $regex;
        $this->compiledPattern = $this->compile($this->uri);
        return $this;
    }

    public function getMethod(): string
    {
        return $this->method;
    }
    public function getUri(): string
    {
        return $this->uri;
    }
    public function getAction(): mixed
    {
        return $this->action;
    }
    public function getName(): ?string
    {
        return $this->name;
    }
    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }

    /** Try to match the given URI. Returns extracted params (may be empty) or null on no match. */
    public function match(string $uri): ?array
    {
        if (!preg_match($this->compiledPattern, $uri, $matches)) {
            return null;
        }

        // Keep only named captures with non-empty values (empty = unmatched optional group).
        return array_filter(
            $matches,
            fn($v, $k) => !is_int($k) && $v !== '',
            ARRAY_FILTER_USE_BOTH
        );
    }

    /** Generate a URL for this route given the parameter values. */
    public function generateUrl(array $params = []): string
    {
        $uri = $this->uri;

        // Replace {param} and {param?} segments, consuming the preceding '/'
        // so that omitted optional params don't leave a dangling slash.
        $uri = preg_replace_callback('#/?\{(\w+)\??\}#', function ($m) use (&$params) {
            $key = $m[1];
            if (isset($params[$key])) {
                $val = $params[$key];
                unset($params[$key]);
                return '/' . (string) $val;
            }
            return '';
        }, $uri);

        // Remaining params as query string
        if (!empty($params)) {
            $uri .= '?' . http_build_query($params);
        }

        // Collapse any duplicate slashes left behind.
        $uri = preg_replace('#/{2,}#', '/', (string) $uri);

        return rtrim((string) $uri, '/') ?: '/';
    }

    private function compile(string $uri): string
    {
        // Consume the preceding '/' together with the parameter so that optional
        // params don't leave a dangling required slash in the pattern.
        $pattern = preg_replace_callback('/\/\{(\w+)(\?)?\}/', function ($m) {
            $name    = $m[1];
            $optional = !empty($m[2]);
            $regex   = $this->wheres[$name] ?? '[^/]+';
            $segment = "(?P<{$name}>{$regex})";
            // Optional: include the leading '/' inside the group so it becomes truly optional.
            return $optional ? "(?:/{$segment})?" : "/{$segment}";
        }, $uri);

        $pattern = str_replace('/', '\/', (string) $pattern);
        return '/^' . $pattern . '\/?$/';
    }
}
