<?php

declare(strict_types=1);

use Marrow\Application;
use Marrow\Config\Repository as ConfigRepository;
use Marrow\Exceptions\HttpException;

if (!function_exists('app')) {
    function app(?string $abstract = null): mixed
    {
        $instance = Application::getInstance();
        if ($abstract === null) {
            return $instance;
        }
        return $instance->getContainer()->make($abstract);
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return app(ConfigRepository::class)->get($key, $default);
    }
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false) {
            return $default;
        }
        return match (strtolower((string) $value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}

if (!function_exists('route')) {
    function route(string $name, array $params = []): string
    {
        return app(\Marrow\Routing\Router::class)->route($name, $params);
    }
}

if (!function_exists('abort')) {
    /** @throws HttpException Always — this is how HttpException is meant to be raised from app code. */
    function abort(int $code, string $message = ''): never
    {
        throw new HttpException($code, $message);
    }
}

if (!function_exists('abort_if')) {
    function abort_if(bool $condition, int $code, string $message = ''): void
    {
        if ($condition) {
            abort($code, $message);
        }
    }
}

if (!function_exists('abort_unless')) {
    function abort_unless(bool $condition, int $code, string $message = ''): void
    {
        if (!$condition) {
            abort($code, $message);
        }
    }
}

if (!function_exists('redirect')) {
    function redirect(string $url, int $status = 302): \Marrow\Http\RedirectResponse
    {
        return new \Marrow\Http\RedirectResponse($url, $status);
    }
}

if (!function_exists('view')) {
    function view(string $template, array $data = []): string
    {
        return app(\Marrow\Template\Engine::class)->render($template, $data);
    }
}

if (!function_exists('now')) {
    function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return Application::getInstance()->getBasePath($path);
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return Application::getInstance()->path('storage', $path);
    }
}

if (!function_exists('public_path')) {
    function public_path(string $path = ''): string
    {
        return Application::getInstance()->path('public', $path);
    }
}

if (!function_exists('bcrypt')) {
    function bcrypt(string $password): string
    {
        return \Marrow\Auth\Hash::make($password);
    }
}

if (!function_exists('class_basename')) {
    function class_basename(string|object $class): string
    {
        $class = is_object($class) ? get_class($class) : $class;
        $parts = preg_split('#[\\\\/]#', $class);
        return end($parts);
    }
}

if (!function_exists('str_slug')) {
    function str_slug(string $text, string $separator = '-'): string
    {
        $text = preg_replace('/[^\pL\d]+/u', $separator, $text);
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $text);
        $text = preg_replace('/[^a-z0-9' . preg_quote($separator, '/') . ']+/i', '', (string) $text);
        return strtolower(trim((string) $text, $separator));
    }
}

if (!function_exists('collect')) {
    function collect(array $items = []): \Marrow\Support\Collection
    {
        return new \Marrow\Support\Collection($items);
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return app(\Marrow\Session\SessionManager::class)->csrfToken();
    }
}

if (!function_exists('logger')) {
    /** PSR-3 logger. Call with no args to get the logger, or pass a message to log at info level. */
    function logger(?string $message = null, array $context = []): \Psr\Log\LoggerInterface
    {
        $log = app(\Psr\Log\LoggerInterface::class);
        if ($message !== null) {
            $log->info($message, $context);
        }
        return $log;
    }
}

if (!function_exists('json')) {
    /** Build a JSON response. */
    function json(mixed $data, int $status = 200, array $headers = []): \Marrow\Http\JsonResponse
    {
        return new \Marrow\Http\JsonResponse($data, $status, $headers);
    }
}

if (!function_exists('cache')) {
    /**
     * Access the cache. No args → the CacheManager; one arg → get; two+ → put.
     */
    function cache(?string $key = null, mixed $value = null, int $ttl = 3600): mixed
    {
        $cache = app(\Marrow\Cache\CacheManager::class);
        if ($key === null) {
            return $cache;
        }
        if (func_num_args() === 1) {
            return $cache->get($key);
        }
        $cache->put($key, $value, $ttl);
        return $value;
    }
}

if (!function_exists('dispatch')) {
    /** Push a job onto the queue. */
    function dispatch(\Marrow\Queue\Job $job): int
    {
        return app(\Marrow\Queue\QueueManager::class)->push($job);
    }
}

if (!function_exists('event')) {
    /** Dispatch an event through the event dispatcher. */
    function event(object $event): void
    {
        app(\Marrow\Events\Dispatcher::class)->dispatch($event);
    }
}
