<?php

declare(strict_types=1);

namespace Marrow\Console\Commands;

use Marrow\Console\Command;
use Marrow\Routing\Router;

/**
 * Prints every registered route: method, URI, name, action, and middleware.
 */
class RouteListCommand extends Command
{
    protected string $signature   = 'route:list {--json : Machine-readable output, e.g. for a script or an AI agent}';
    protected string $description = 'List all registered routes';

    public function __construct(private readonly Router $router)
    {
        parent::__construct();
    }

    protected function handle(): int
    {
        $routes = [];

        foreach ($this->router->getRoutes()->all() as $method => $methodRoutes) {
            foreach ($methodRoutes as $route) {
                $action = $route->getAction();
                $action = is_array($action)
                    ? implode('@', [class_basename($action[0]), $action[1]])
                    : (is_callable($action) ? 'Closure' : (string) $action);

                $routes[] = [
                    'method'     => strtoupper($method),
                    'uri'        => $route->getUri(),
                    'name'       => $route->getName() ?? '',
                    'action'     => $action,
                    'middleware' => $route->getMiddlewares(),
                ];
            }
        }

        if ($this->option('json')) {
            $this->output->writeln((string) json_encode($routes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        if (empty($routes)) {
            $this->info('No routes registered.');
            return self::SUCCESS;
        }

        $this->newLine();

        foreach ($routes as $r) {
            $methodBadge = $this->methodBadge($r['method']);
            $uri         = mb_strimwidth($r['uri'], 0, 40, '…');
            $name        = mb_strimwidth($r['name'], 0, 28, '…');
            $action      = mb_strimwidth($r['action'], 0, 38, '…');

            $uriPad    = str_pad($uri, 40);
            $namePad   = str_pad($name, 28);

            $this->output->writeln(
                "   {$methodBadge}  {$uriPad}  <fg=gray>{$namePad}</>  {$action}"
            );
        }

        $this->newLine();

        return self::SUCCESS;
    }

    private function methodBadge(string $method): string
    {
        $padded = str_pad($method, 7);

        return match ($method) {
            'GET'     => "<options=bold;fg=cyan>{$padded}</>",
            'POST'    => "<options=bold;fg=green>{$padded}</>",
            'PUT',
            'PATCH'   => "<options=bold;fg=yellow>{$padded}</>",
            'DELETE'  => "<options=bold;fg=red>{$padded}</>",
            default   => "<fg=gray>{$padded}</>",
        };
    }
}
