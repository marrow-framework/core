<?php

declare(strict_types=1);

namespace Marrow\Template;

use Marrow\Container;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Twig engine wrapper. Manages namespaces, view composers, and global data.
 * App code only ever imports Marrow\Template\Engine — never Twig directly.
 */
class Engine
{
    private Environment $twig;
    private FilesystemLoader $loader;

    /** @var array<string, callable[]> Template name → list of composer callbacks */
    private array $composers = [];

    /** @var array<string, mixed> Globals shared to every template */
    private array $globals = [];

    public function __construct(
        private readonly Container $container,
        string $viewsPath,
        string $cachePath,
        bool $debug = false
    ) {
        $this->loader = new FilesystemLoader();

        // Root namespace (resources/views/)
        if (is_dir($viewsPath)) {
            $this->loader->addPath($viewsPath);
        }

        // Built-in view subdirectory namespaces
        foreach (['layouts', 'errors', 'components', 'partials', 'emails'] as $ns) {
            $nsPath = $viewsPath . '/' . $ns;
            if (is_dir($nsPath)) {
                $this->loader->addPath($nsPath, $ns);
            }
        }

        // Core error views fallback namespace
        $coreViews = dirname(__DIR__) . '/Exceptions/views';
        if (is_dir($coreViews)) {
            $this->loader->addPath($coreViews, 'core_errors');
        }

        $this->twig = new Environment($this->loader, [
            // Always cache compiled templates; auto_reload picks up source changes.
            // Disabling cache in debug mode forces a full recompile on every
            // request and is the single biggest performance drain in development.
            'cache' => $cachePath,
            'auto_reload' => true,
            'debug' => $debug,
            'strict_variables' => $debug,
        ]);

        if ($debug) {
            $this->twig->addExtension(new \Twig\Extension\DebugExtension());
        }

        $this->twig->addExtension(new FrameworkExtension($container));
    }

    public function render(string $template, array $data = []): string
    {
        // Resolve @namespace/path → @namespace/path (Twig uses @ prefix natively via setNamespace)
        $template = $this->normalizeTemplate($template);

        $data = array_merge($this->globals, $data);

        // Apply composers
        foreach ($this->composers as $pattern => $callbacks) {
            if ($this->templateMatchesPattern($template, $pattern)) {
                foreach ($callbacks as $callback) {
                    $view = new ViewData($data);
                    $callback($view);
                    $data = array_merge($data, $view->getData());
                }
            }
        }

        return $this->twig->render($template, $data);
    }

    public function addNamespace(string $namespace, string $path): void
    {
        if (is_dir($path)) {
            $this->loader->addPath($path, $namespace);
        }
    }

    /** Register a view composer — called before every render matching pattern. */
    public function composer(string $template, callable $callback): void
    {
        $this->composers[$template][] = $callback;
    }

    /** Share a variable across all templates for this request. */
    public function shareGlobal(string $key, mixed $value): void
    {
        $this->globals[$key] = $value;
        $this->twig->addGlobal($key, $value);
    }

    public function getTwig(): Environment
    {
        return $this->twig;
    }

    private function normalizeTemplate(string $template): string
    {
        // Automatically append .html.twig if no extension is provided
        if (!preg_match('/\.[\w]+$/', $template)) {
            $template .= '.html.twig';
        }
        return $template;
    }

    private function templateMatchesPattern(string $template, string $pattern): bool
    {
        return fnmatch($pattern, $template) || $template === $pattern;
    }
}
