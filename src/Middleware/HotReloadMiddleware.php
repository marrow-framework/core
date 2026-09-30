<?php

declare(strict_types=1);

namespace Marrow\Middleware;

use Marrow\Application;
use Marrow\Http\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Auto-refreshes the browser during local development, for a project that
 * isn't running the Vite dev server at all (a pure API backend, or the
 * frontend pipeline isn't installed).
 *
 * Polls GET /__marrow/ping every 800ms for an mtime hash over watched
 * source files, injected into every HTML response; the browser reloads
 * when the hash changes. Steps aside entirely (no ping, no injected
 * script) once Vite's dev server is running (public/hot present) — Vite's
 * own `refresh` option (vite.config.js) already covers PHP source and
 * templates over its WebSocket, faster and without polling, so running
 * both would only risk a stale reload landing on top of a change Vite
 * already applied instantly.
 *
 * Enabled only when APP_ENV=local or APP_DEBUG=true; never in production.
 */
class HotReloadMiddleware
{
    private const PING_PATH = '/__marrow/ping';

    public function __construct(private readonly Application $app)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        if (!$this->isDevMode() || $this->viteDevServerRunning()) {
            return $next($request);
        }

        if ($request->getPathInfo() === self::PING_PATH) {
            return $this->pingResponse();
        }

        /** @var Response $response */
        $response = $next($request);

        $ct = (string) $response->headers->get('Content-Type', '');
        if (str_contains($ct, 'text/html') || $ct === '') {
            $body = $response->getContent();
            if (is_string($body) && str_contains($body, '</body>')) {
                $response->setContent(
                    str_replace('</body>', $this->injectScript() . '</body>', $body)
                );
            }
        }

        return $response;
    }

    private function pingResponse(): JsonResponse
    {
        $response = new JsonResponse(['hash' => $this->computeHash()]);
        $response->headers->set('Cache-Control', 'no-store');
        return $response;
    }

    /** Fast enough for 800ms polling; scans up to ~10k files in <5ms. */
    private function computeHash(): string
    {
        $basePath = $this->app->getBasePath();
        $watchDirs = [
            $basePath . '/app',
            $basePath . '/modules',
            $basePath . '/resources',
            $basePath . '/config',
            $basePath . '/routes',
        ];

        $extensions = ['php', 'twig', 'html', 'css', 'js'];
        $maxMtime   = 0;
        $fileCount  = 0;

        foreach ($watchDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if (!$file->isFile()) {
                    continue;
                }
                if (!in_array(strtolower($file->getExtension()), $extensions, true)) {
                    continue;
                }
                $mtime = $file->getMTime();
                if ($mtime > $maxMtime) {
                    $maxMtime = $mtime;
                }
                $fileCount++;
            }
        }

        // Also watch framework src when running from the monorepo.
        $frameworkSrc = $basePath . '/../framework/src';
        if (is_dir($frameworkSrc)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($frameworkSrc, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $mtime = $file->getMTime();
                    if ($mtime > $maxMtime) {
                        $maxMtime = $mtime;
                    }
                    $fileCount++;
                }
            }
        }

        return md5("{$maxMtime}:{$fileCount}");
    }

    private function injectScript(): string
    {
        return <<<'HTML'
<script>
(function () {
  var _hash = null, _url = '/__marrow/ping';
  function check() {
    fetch(_url, { cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d) return;
        if (_hash !== null && _hash !== d.hash) { location.reload(); return; }
        _hash = d.hash;
      })
      .catch(function () {});
  }
  check();
  setInterval(check, 800);
})();
</script>
HTML;
    }

    private function isDevMode(): bool
    {
        $env   = strtolower((string) ($_ENV['APP_ENV'] ?? 'production'));
        $debug = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);
        return $env === 'local' || $debug;
    }

    /** Same signal Template\FrameworkExtension::funcViteDevMode() uses. */
    private function viteDevServerRunning(): bool
    {
        return is_file($this->app->path('public', 'hot'));
    }
}
