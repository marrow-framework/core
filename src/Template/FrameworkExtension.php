<?php

declare(strict_types=1);

namespace Marrow\Template;

use Marrow\Application;
use Marrow\Container;
use Marrow\Routing\Router;
use Marrow\Template\ComponentRegistry;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Twig\TwigTest;

/**
 * Twig extension exposing all Marrow helpers:
 * functions (route, asset, csrf_token, auth_user, config, old, errors, current_route, ...),
 * filters (truncate, slug, markdown, time_ago, money),
 * tests (admin),
 * globals (app).
 */
class FrameworkExtension extends AbstractExtension implements GlobalsInterface
{
    private readonly Application $app;
    private readonly Router $router;

    public function __construct(private readonly Container $container)
    {
        // Resolved once at construction from the injected Container — not the
        // global Application::getInstance() singleton — so a misconfiguration
        // fails fast here instead of being re-resolved ambiently on every call.
        $this->app = $container->make(Application::class);
        $this->router = $container->make(Router::class);
    }

    // ──────────────────────── Functions ─────────────────────────────

    public function getFunctions(): array
    {
        $safe = ['is_safe' => ['html']];

        return [
            new TwigFunction('route', [$this, 'funcRoute']),
            new TwigFunction('asset', [$this, 'funcAsset']),
            new TwigFunction('vite_asset', [$this, 'funcViteAsset']),
            new TwigFunction('vite_dev_mode', [$this, 'funcViteDevMode']),
            new TwigFunction('vite_client', [$this, 'funcViteClient'], $safe),
            new TwigFunction('csrf_token', [$this, 'funcCsrfToken']),
            new TwigFunction('csrf_field', [$this, 'funcCsrfField'], $safe),
            new TwigFunction('method_field', [$this, 'funcMethodField'], $safe),
            new TwigFunction('auth_user', [$this, 'funcAuthUser']),
            new TwigFunction('auth_check', [$this, 'funcAuthCheck']),
            new TwigFunction('config', [$this, 'funcConfig']),
            new TwigFunction('old', [$this, 'funcOld']),
            new TwigFunction('errors', [$this, 'funcErrors']),
            new TwigFunction('has_error', [$this, 'funcHasError']),
            new TwigFunction('component', [$this, 'funcComponent'], $safe),
            new TwigFunction('dump', [$this, 'funcDump'], ['is_safe' => ['html'], 'needs_context' => true]),
            new TwigFunction('session_flash', [$this, 'funcSessionFlash']),
            new TwigFunction('can',    [$this, 'funcCan']),
            new TwigFunction('cannot', [$this, 'funcCannot']),
            new TwigFunction('gate',   [$this, 'funcGate']),
            new TwigFunction('current_route', [$this, 'funcCurrentRoute']),
        ];
    }

    /** The name of the currently dispatched route, or null (e.g. 404, CLI render). */
    public function funcCurrentRoute(): ?string
    {
        return $this->router->getCurrentRoute()?->getName();
    }

    public function funcRoute(string $name, array $params = []): string
    {
        return $this->container->make(\Marrow\Routing\Router::class)->route($name, $params);
    }

    public function funcAsset(string $path): string
    {
        $publicPath = $this->app->path('public', $path);
        $mtime = is_file($publicPath) ? filemtime($publicPath) : 0;
        $base = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
        return $base . '/' . ltrim($path, '/') . ($mtime ? "?v={$mtime}" : '');
    }

    /**
     * Return the hashed URL for a Vite-compiled asset.
     * In dev (HMR) mode points to the Vite dev server; in prod reads the manifest.
     */
    public function funcViteAsset(string $entry): string
    {
        static $manifest = null;

        $hotFilePath = $this->app->path('public', 'hot');

        // Vite dev-server HMR mode
        if (is_file($hotFilePath)) {
            $base = rtrim((string) file_get_contents($hotFilePath), "/\n");
            return $base . '/' . ltrim($entry, '/');
        }

        // Production: read the manifest. Checked at two locations because
        // where it actually lands depends on the plugin, not just the Vite
        // version: vanilla Vite writes build.manifest=true to the nested
        // .vite/manifest.json, but laravel-vite-plugin (verified against
        // v3.2 / Vite 8, the combination in the skeleton's package.json)
        // points it at the flat build/manifest.json instead.
        if ($manifest === null) {
            $manifest = [];
            foreach (['build/manifest.json', 'build/.vite/manifest.json'] as $relative) {
                $manifestPath = $this->app->path('public', $relative);
                if (is_file($manifestPath)) {
                    $manifest = json_decode((string) file_get_contents($manifestPath), true) ?? [];
                    break;
                }
            }
        }

        if (isset($manifest[$entry]['file'])) {
            return '/build/' . $manifest[$entry]['file'];
        }

        // Fallback: serve directly (dev without HMR or missing manifest)
        return '/build/' . $entry;
    }

    /**
     * True while the Vite dev server is running (public/hot present).
     *
     * A CSS entry needs different markup in each mode — a <script
     * type="module"> in dev (Vite serves CSS as a hot-reloadable JS module),
     * a <link rel="stylesheet"> once built for production — so a template
     * branches on this rather than vite_asset() trying to paper over the
     * difference itself:
     *
     *   {% if vite_dev_mode() %}
     *       {{ vite_client()|raw }}
     *       <script type="module" src="{{ vite_asset('resources/css/app.css') }}"></script>
     *   {% else %}
     *       <link rel="stylesheet" href="{{ vite_asset('resources/css/app.css') }}">
     *   {% endif %}
     */
    public function funcViteDevMode(): bool
    {
        return is_file($this->app->path('public', 'hot'));
    }

    /**
     * The Vite HMR client bootstrap <script> tag — required once per page in
     * dev mode for hot-reload/HMR to actually connect; vite_asset() alone
     * only resolves individual entry URLs, it doesn't inject this. Returns
     * '' in production, where there is no dev server to connect to.
     */
    public function funcViteClient(): string
    {
        $hotFilePath = $this->app->path('public', 'hot');
        if (!is_file($hotFilePath)) {
            return '';
        }
        $base = rtrim((string) file_get_contents($hotFilePath), "/\n");
        return '<script type="module" src="' . htmlspecialchars($base) . '/@vite/client"></script>';
    }

    /**
     * Render a view component by name.
     * {{ component('alert', {type: 'success', message: 'Saved!'}) }}
     */
    public function funcComponent(string $name, array $props = []): string
    {
        try {
            $registry = $this->container->make(ComponentRegistry::class);
            $class = $registry->resolve($name);

            if ($class === null) {
                throw new \RuntimeException("Component [{$name}] is not registered.");
            }

            $component = new $class($props);
            $engine = $this->container->make(Engine::class);

            return $engine->render($component->render(), array_merge($props, $component->data()));
        } catch (\Throwable $e) {
            if ((bool) ($_ENV['APP_DEBUG'] ?? false)) {
                return '<div style="color:red;border:1px solid red;padding:4px;font-family:monospace">'
                    . 'Component [' . htmlspecialchars($name) . '] error: '
                    . htmlspecialchars($e->getMessage())
                    . '</div>';
            }
            return '';
        }
    }

    public function funcCsrfToken(): string
    {
        return $this->container->make(\Marrow\Session\SessionManager::class)->csrfToken();
    }

    public function funcCsrfField(): string
    {
        $token = $this->funcCsrfToken();
        return "<input type=\"hidden\" name=\"_token\" value=\"{$token}\">";
    }

    public function funcMethodField(string $method): string
    {
        return "<input type=\"hidden\" name=\"_method\" value=\"{$method}\">";
    }

    public function funcAuthUser(): mixed
    {
        try {
            return $this->container->make(\Marrow\Auth\AuthManager::class)->user();
        } catch (\Throwable) {
            return null;
        }
    }

    public function funcAuthCheck(): bool
    {
        try {
            return $this->container->make(\Marrow\Auth\AuthManager::class)->check();
        } catch (\Throwable) {
            return false;
        }
    }

    public function funcConfig(string $key, mixed $default = null): mixed
    {
        return $this->container->make(\Marrow\Config\Repository::class)->get($key, $default);
    }

    public function funcOld(string $key, mixed $default = ''): mixed
    {
        try {
            $session = $this->container->make(\Marrow\Session\SessionManager::class);
            return $session->get('_old_input.' . $key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    public function funcErrors(?string $key = null): mixed
    {
        try {
            $session = $this->container->make(\Marrow\Session\SessionManager::class);
            $errors = $session->get('_errors', []);
            if ($key === null) {
                return $errors;
            }
            return $errors[$key] ?? [];
        } catch (\Throwable) {
            return $key ? [] : [];
        }
    }

    public function funcHasError(string $key): bool
    {
        return !empty($this->funcErrors($key));
    }

    /** @var array<string, string|null> Per-request flash cache so repeated calls return the same value. */
    private array $flashCache = [];

    /**
     * Read (and consume) a flash message from the session.
     * Cached within the request so calling it multiple times (check + display) is safe.
     *
     * Usage: {% if session_flash('success') is not null %}
     *        {% set msg = session_flash('success') %}
     */
    public function funcSessionFlash(string $type): ?string
    {
        if (array_key_exists($type, $this->flashCache)) {
            return $this->flashCache[$type];
        }

        try {
            $session = $this->container->make(\Marrow\Session\SessionManager::class);
            $value   = $session->pull($type);  // reads once and removes from session
            $this->flashCache[$type] = $value !== null ? (string) $value : null;
        } catch (\Throwable) {
            $this->flashCache[$type] = null;
        }

        return $this->flashCache[$type];
    }

    /**
     * Check if the current user has a Gate ability.
     * Usage in Twig: {% if can('update', post) %} ... {% endif %}
     */
    public function funcCan(string $ability, mixed ...$arguments): bool
    {
        // No try/catch: a Gate resolution failure is a real misconfiguration and
        // must surface as an error, not silently read as "permission denied" —
        // the two are indistinguishable to a template author otherwise.
        $gate = $this->container->make(\Marrow\Auth\Gate::class);
        return $gate->allows($ability, empty($arguments) ? [] : $arguments);
    }

    public function funcCannot(string $ability, mixed ...$arguments): bool
    {
        return !$this->funcCan($ability, ...$arguments);
    }

    /** Returns the Gate instance for advanced usage in templates. */
    public function funcGate(): \Marrow\Auth\Gate
    {
        return $this->container->make(\Marrow\Auth\Gate::class);
    }

    public function funcDump(array $context, mixed ...$vars): string
    {
        if (empty($vars)) {
            $vars = array_values($context);
        }
        ob_start();
        foreach ($vars as $v) {
            \Symfony\Component\VarDumper\VarDumper::dump($v);
        }
        return (string) ob_get_clean();
    }

    // ──────────────────────── Filters ────────────────────────────────

    public function getFilters(): array
    {
        return [
            new TwigFilter('truncate', [$this, 'filterTruncate']),
            new TwigFilter('slug', [$this, 'filterSlug']),
            new TwigFilter('markdown', [$this, 'filterMarkdown'], ['is_safe' => ['html']]),
            new TwigFilter('time_ago', [$this, 'filterTimeAgo']),
            new TwigFilter('money', [$this, 'filterMoney']),
        ];
    }

    public function filterTruncate(string $text, int $length = 120, string $suffix = '…'): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        $truncated = mb_substr($text, 0, $length);
        $lastSpace = mb_strrpos($truncated, ' ');
        if ($lastSpace !== false) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }
        return $truncated . $suffix;
    }

    public function filterSlug(string $text): string
    {
        return str_slug($text);
    }

    public function filterMarkdown(string $text): string
    {
        // Minimal inline Markdown renderer
        $text = htmlspecialchars($text, ENT_QUOTES);

        // Headings
        $text = preg_replace('/^#{3}\s+(.+)$/m', '<h3>$1</h3>', $text);
        $text = preg_replace('/^#{2}\s+(.+)$/m', '<h2>$1</h2>', $text);
        $text = preg_replace('/^#{1}\s+(.+)$/m', '<h1>$1</h1>', $text);

        // Bold, italic
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);

        // Inline code
        $text = preg_replace('/`(.+?)`/', '<code>$1</code>', $text);

        // Links
        $text = preg_replace('/\[(.+?)\]\((.+?)\)/', '<a href="$2">$1</a>', $text);

        // Unordered list items
        $text = preg_replace('/^[-*]\s+(.+)$/m', '<li>$1</li>', $text);
        $text = preg_replace('/(<li>.*<\/li>)/s', '<ul>$1</ul>', $text);

        // Paragraphs
        $text = preg_replace('/\n\n+/', '</p><p>', $text);

        return '<p>' . $text . '</p>';
    }

    public function filterTimeAgo(\DateTimeInterface|string $date): string
    {
        if (is_string($date)) {
            $date = new \DateTimeImmutable($date);
        }
        $diff = (new \DateTimeImmutable())->getTimestamp() - $date->getTimestamp();

        return match (true) {
            $diff < 60 => 'à l\'instant',
            $diff < 3600 => 'il y a ' . (int) ($diff / 60) . ' minute' . ((int) ($diff / 60) > 1 ? 's' : ''),
            $diff < 86400 => 'il y a ' . (int) ($diff / 3600) . ' heure' . ((int) ($diff / 3600) > 1 ? 's' : ''),
            $diff < 2592000 => 'il y a ' . (int) ($diff / 86400) . ' jour' . ((int) ($diff / 86400) > 1 ? 's' : ''),
            $diff < 31536000 => 'il y a ' . (int) ($diff / 2592000) . ' mois',
            default => 'il y a ' . (int) ($diff / 31536000) . ' an' . ((int) ($diff / 31536000) > 1 ? 's' : ''),
        };
    }

    public function filterMoney(float $amount, string $dec = ',', string $thou = ' ', string $symbol = '€'): string
    {
        return number_format($amount, 2, $dec, $thou) . ' ' . $symbol;
    }

    // ──────────────────────── Tests ──────────────────────────────────

    public function getTests(): array
    {
        return [
            new TwigTest('admin', [$this, 'testIsAdmin']),
        ];
    }

    public function testIsAdmin(mixed $user): bool
    {
        if ($user === null) {
            return false;
        }
        return (bool) (is_array($user) ? ($user['is_admin'] ?? false) : ($user->is_admin ?? false));
    }

    // ──────────────────────── Globals ────────────────────────────────

    public function getGlobals(): array
    {
        return [
            'app' => [
                'name' => $_ENV['APP_NAME'] ?? 'Marrow',
                'env' => $_ENV['APP_ENV'] ?? 'production',
                'debug' => (bool) ($_ENV['APP_DEBUG'] ?? false),
                'version' => $_ENV['APP_VERSION'] ?? '0.1.0',
            ],
        ];
    }
}
