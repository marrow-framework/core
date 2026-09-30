<?php

declare(strict_types=1);

namespace Marrow;

use Marrow\Auth\AuthManager;
use Marrow\Auth\Gate;
use Marrow\Cache\CacheManager;
use Marrow\Config\Repository as ConfigRepository;
use Marrow\Filesystem\Storage;
use Marrow\Console\Kernel as ConsoleKernel;
use Marrow\Container;
use Marrow\Events\Dispatcher;
use Marrow\Exceptions\Handler as ExceptionHandler;
use Marrow\Http\Kernel as HttpKernel;
use Marrow\Http\Request;
use Marrow\Http\Shield\ShieldConfig;
use Marrow\Logging\Logger;
use Marrow\Module\ModuleManager;
use Marrow\Routing\Router;
use Marrow\Session\SessionManager;
use Marrow\Template\ComponentRegistry;
use Marrow\Template\Engine as TemplateEngine;
use Marrow\Database\Connection;
use Marrow\Validation\ValidatorFactory;
use Dotenv\Dotenv;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The Application is the central IoC kernel. It holds the base path,
 * wires up all core service bindings and boots the module system.
 */
class Application
{
    private static self $instance;
    private Container $container;
    private ConfigRepository $config;
    private bool $booted = false;

    /** Conventioned sub-paths, all relative to basePath and overridable. */
    private array $paths = [];

    public function __construct(private readonly string $basePath)
    {
        self::$instance = $this;
        $this->paths = [
            'config' => $basePath . '/config',
            'modules' => $basePath . '/modules',
            'storage' => $basePath . '/storage',
            'cache' => $basePath . '/storage/cache',
            'logs' => $basePath . '/storage/logs',
            'views' => $basePath . '/resources/views',
            'public' => $basePath . '/public',
        ];

        $this->container = new Container();
        $this->container->instance(Application::class, $this);
        $this->container->instance(Container::class, $this->container);

        $this->loadEnvironment();
        $this->bindCoreServices();
    }

    public static function getInstance(): self
    {
        return self::$instance;
    }

    public function getContainer(): Container
    {
        return $this->container;
    }

    public function getBasePath(string $path = ''): string
    {
        return $path ? $this->basePath . '/' . ltrim($path, '/') : $this->basePath;
    }

    public function path(string $key, string $append = ''): string
    {
        $base = $this->paths[$key] ?? $this->basePath . '/' . $key;
        return $append ? $base . '/' . ltrim($append, '/') : $base;
    }

    public function setPath(string $key, string $path): void
    {
        $this->paths[$key] = $path;
    }

    public function configure(string $name): void
    {
        $file = $this->path('config') . '/' . $name . '.php';
        if (is_file($file)) {
            $values = require $file;
            $this->config->set($name, $values);
        }
    }

    public function handleRequest(): void
    {
        $this->boot();

        /** @var HttpKernel $kernel */
        $kernel = $this->container->make(HttpKernel::class);
        $request = Request::createFromGlobals();
        $response = $kernel->handle($request);
        $response->send();
    }

    public function runConsole(): never
    {
        $this->boot();

        /** @var ConsoleKernel $kernel */
        $kernel = $this->container->make(ConsoleKernel::class);
        $status = $kernel->handle();
        exit($status);
    }

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        // config/modules.php isn't in bindCoreServices()'s core-config list
        // (loaded eagerly for every app regardless of whether modules are
        // used yet), so it must be loaded explicitly here before its
        // 'modules.enabled' key can be read.
        $this->configure('modules');

        /** @var ModuleManager $manager */
        $manager = $this->container->make(ModuleManager::class);

        // Modules come from two sources: explicitly listed in
        // config/modules.php ('enabled'), and auto-discovered from any
        // installed Composer package that declares itself via
        // extra.marrow.modules in its own composer.json (see
        // PackageDiscovery). 'disabled' lets an app opt a discovered module
        // out without uninstalling the package.
        $configured = (array) $this->config->get('modules.enabled', []);
        $discovered = \Marrow\Module\PackageDiscovery::discover($this->basePath);
        $disabled   = (array) $this->config->get('modules.disabled', []);

        $moduleClasses = array_values(array_diff(
            array_unique(array_merge($configured, $discovered)),
            $disabled
        ));

        foreach ($moduleClasses as $moduleClass) {
            $manager->register($moduleClass);
        }

        $manager->boot();
    }

    private function loadEnvironment(): void
    {
        $dotenv = Dotenv::createImmutable($this->basePath);
        $dotenv->safeLoad();
    }

    private function bindCoreServices(): void
    {
        // Config
        $this->config = new ConfigRepository();
        $this->container->instance(ConfigRepository::class, $this->config);

        // Load core config files immediately so all services can read them
        foreach (['app', 'database', 'logging', 'session', 'cache', 'auth', 'middleware', 'filesystems', 'rbac', 'mail', 'queue', 'notifications', 'cors', 'shield', 'services', 'health'] as $cfg) {
            $this->configure($cfg);
        }

        // Shield — typed security config (headers, CSP, HSTS, CSRF exemptions).
        // See src/Http/Shield/ShieldConfig.php.
        $this->container->singleton(ShieldConfig::class, fn() => ShieldConfig::fromArray(
            (array) $this->config->get('shield', [])
        ));

        // Logger (Monolog-backed) — also bound as PSR-3 LoggerInterface
        $this->container->singleton(Logger::class, function () {
            return new Logger(
                $this->config->get('app.name', 'Marrow'),
                $this->path('logs'),
                $this->config->get('logging.level', 'debug'),
                (bool) $this->config->get('app.debug', false)
            );
        });
        $this->container->bind(LoggerInterface::class, fn() => $this->container->make(Logger::class));

        // Event Dispatcher
        $this->container->singleton(Dispatcher::class, fn() => new Dispatcher($this->container));

        // Router
        $this->container->singleton(Router::class, fn() => new Router($this->container));

        // Template Engine
        $this->container->singleton(TemplateEngine::class, function () {
            return new TemplateEngine(
                $this->container,
                $this->path('views'),
                $this->path('cache', 'twig'),
                (bool) $this->config->get('app.debug', false)
            );
        });

        // Database Connection (lazy) — query log enabled so RequestLogger can count queries
        $this->container->singleton(Connection::class, function () {
            $conn = new Connection($this->config->get('database', []));
            $conn->enableQueryLog();
            return $conn;
        });

        // Module Manager
        $this->container->singleton(ModuleManager::class, function () {
            return new ModuleManager($this->container);
        });

        // HTTP Kernel
        $this->container->singleton(HttpKernel::class, function () {
            return new HttpKernel(
                $this->container,
                $this->container->make(Router::class),
                $this->config->get('middleware', []),
                $this->container->make(ExceptionHandler::class)
            );
        });

        // Exception Handler
        $this->container->singleton(ExceptionHandler::class, function () {
            return new ExceptionHandler(
                $this->container->make(Logger::class),
                $this->container->make(TemplateEngine::class),
                (bool) $this->config->get('app.debug', false),
                $this->path('views', 'errors')
            );
        });

        // Console Kernel
        $this->container->singleton(ConsoleKernel::class, function () {
            return new ConsoleKernel(
                $this->container,
                $this->config->get('app.name', 'Marrow'),
                $this->config->get('app.version', '0.1.0')
            );
        });

        // Session Manager
        $this->container->singleton(SessionManager::class, fn() => new SessionManager());

        // Cache Manager (PSR-6 backed via symfony/cache)
        $this->container->singleton(CacheManager::class, function () {
            return new CacheManager(
                $this->path('cache', 'app'),
                $this->config->get('cache.default', 'file'),
                $this->config->get('cache', [])
            );
        });

        // Auth Manager
        $this->container->singleton(AuthManager::class, fn() => new AuthManager(
            $this->container->make(Connection::class),
            $this->container->make(SessionManager::class),
            $this->config->get('auth', [])
        ));

        // Authorization Gate
        $this->container->singleton(Gate::class, function () {
            $gate        = new Gate(
                $this->container->make(AuthManager::class),
                $this->container
            );
            // Super-admin bypass: users with the configured role pass all Gate checks
            $superAdmin  = $this->config->get('rbac.super_admin_role', 'admin');
            if ($superAdmin) {
                $gate->before(static function (object $user, string $ability) use ($superAdmin): ?bool {
                    if (method_exists($user, 'hasRole') && $user->hasRole($superAdmin)) {
                        return true;
                    }
                    return null;
                });
            }
            return $gate;
        });

        // Validator Factory (no-arg; resolves DB lazily via Application::getInstance())
        $this->container->singleton(ValidatorFactory::class, fn() => new ValidatorFactory());

        // View Component Registry
        $this->container->singleton(ComponentRegistry::class, fn() => new ComponentRegistry());

        // Storage (static helper, registered so it can be type-hinted if needed)
        $this->container->singleton(Storage::class, fn() => Storage::disk());

        // Rate Limiter (sliding window, cache-backed)
        $this->container->singleton(\Marrow\RateLimiting\RateLimiter::class, fn() =>
            new \Marrow\RateLimiting\RateLimiter($this->container->make(CacheManager::class))
        );

        // HTTP Client (outbound requests)
        $this->container->bind(\Marrow\Http\HttpClient::class, fn() =>
            \Marrow\Http\HttpClient::create($this->config->get('services.http', []))
        );

        // Mailer
        $this->container->singleton(\Marrow\Mail\Mailer::class, fn() =>
            \Marrow\Mail\Mailer::fromDsn(
                $this->config->get('mail.dsn', 'null://null'),
                $this->config->get('mail', [])
            )
        );

        // Queue Manager (database-backed)
        $this->container->singleton(\Marrow\Queue\QueueManager::class, fn() =>
            new \Marrow\Queue\QueueManager(
                $this->container->make(Connection::class),
                $this->config->get('queue.table', 'jobs'),
                $this->config->get('queue.failed_table', 'failed_jobs')
            )
        );

        // Queue Worker
        $this->container->singleton(\Marrow\Queue\Worker::class, fn() =>
            new \Marrow\Queue\Worker(
                $this->container->make(\Marrow\Queue\QueueManager::class),
                $this->container->make(Logger::class),
                $this->container
            )
        );

        // Task Scheduler — shared instance so modules can register events in boot()
        $this->container->singleton(\Marrow\Scheduling\Schedule::class, fn() =>
            new \Marrow\Scheduling\Schedule($this, $this->container->make(\Marrow\Queue\QueueManager::class))
        );

        // Notification Manager
        $this->container->singleton(\Marrow\Notifications\NotificationManager::class, fn() =>
            new \Marrow\Notifications\NotificationManager(
                $this->container->make(\Marrow\Mail\Mailer::class),
                $this->container->make(Connection::class),
                $this->config->get('notifications.table', 'notifications')
            )
        );

        // Health Manager — register default checks (DB, cache, disk, queue).
        // Thresholds and which checks are enabled come from config/health.php
        // so ops can tune them without touching framework code.
        $this->container->singleton(\Marrow\Health\HealthManager::class, function () {
            $manager = new \Marrow\Health\HealthManager();
            $enabled = (array) $this->config->get('health.enabled', ['database', 'cache', 'disk', 'queue']);

            if (in_array('database', $enabled, true)) {
                $manager->register(new \Marrow\Health\Checks\DatabaseHealthCheck(
                    $this->container->make(Connection::class)
                ));
            }

            if (in_array('cache', $enabled, true)) {
                $manager->register(new \Marrow\Health\Checks\CacheHealthCheck(
                    $this->container->make(CacheManager::class)
                ));
            }

            if (in_array('disk', $enabled, true)) {
                $manager->register(new \Marrow\Health\Checks\DiskSpaceHealthCheck(
                    $this->path('storage'),
                    (float) $this->config->get('health.disk.warn_percent', 85.0),
                    (float) $this->config->get('health.disk.fail_percent', 95.0)
                ));
            }

            if (in_array('queue', $enabled, true)) {
                $manager->register(new \Marrow\Health\Checks\QueueHealthCheck(
                    $this->container->make(Connection::class),
                    (string) $this->config->get('queue.table', 'jobs'),
                    (string) $this->config->get('queue.failed_table', 'failed_jobs'),
                    (int) $this->config->get('health.queue.backlog_warn', 100),
                    (int) $this->config->get('health.queue.backlog_fail', 1000),
                    (int) $this->config->get('health.queue.failed_warn', 1),
                    (int) $this->config->get('health.queue.failed_fail', 50)
                ));
            }

            return $manager;
        });
    }

    public function version(): string
    {
        return $this->config->get('app.version', '0.1.0');
    }

    public function environment(): string
    {
        return $this->config->get('app.env', 'production');
    }

    public function isDebug(): bool
    {
        return (bool) $this->config->get('app.debug', false);
    }
}
