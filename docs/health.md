# Health Checks

`Marrow\Health\HealthManager` aggregates pluggable probes into one
report — wire it to a `/health` route for load balancers, uptime monitors,
or container orchestrators to poll.

## Built-in checks

Registered automatically in `Application::bindCoreServices()`, driven by
`config/health.php`:

| Check | Verifies |
|---|---|
| `DatabaseHealthCheck` | the configured `Connection` can run a query |
| `CacheHealthCheck` | the configured cache driver can write/read |
| `DiskSpaceHealthCheck` | free space on the storage path is above a threshold |
| `QueueHealthCheck` | the `jobs`/`failed_jobs` tables' row counts are below a threshold — catches a stuck or dead queue worker before it becomes an unnoticed outage |

```php
// config/health.php
return [
    'enabled' => ['database', 'cache', 'disk', 'queue'],   // drop one to stop running it
    'disk' => ['warn_percent' => 85.0, 'fail_percent' => 95.0],
    'queue' => [
        'backlog_warn' => 100, 'backlog_fail' => 1000,
        'failed_warn' => 1, 'failed_fail' => 50,
    ],
];
```

`HealthManager::report()` measures every check centrally and adds
`duration_ms` to each one's result — including third-party checks that
don't self-time — so a slow overall report can be attributed to a specific
probe rather than guessed at.

`QueueHealthCheck` degrades to `warning` (not `failed`) if the `jobs`/
`failed_jobs` tables don't exist yet (queue not migrated) — a missing
optional feature shouldn't fail the whole app's health report.

## Exposing the endpoint

The skeleton's `Home` module already does this:

```php
// modules/Home/routes.php
$router->get('/health', [HomeController::class, 'health']);
```

```php
public function health(\Marrow\Health\HealthManager $health): JsonResponse
{
    $report = $health->report();
    return $this->json($report, $report['status'] === 'ok' ? 200 : 503);
}
```

Response shape:

```json
{
  "status": "ok",
  "checks": {
    "database": {"status": "ok", "message": "OK"},
    "cache": {"status": "ok", "message": "OK"},
    "disk": {"status": "ok", "message": "OK", "meta": {"free_bytes": 12345678}}
  },
  "duration_ms": 4.21
}
```

`status` is the worst of the individual checks (`failed` > `warning` > `ok`)
— return the corresponding HTTP status from your route (`503` on `failed`
is the conventional choice for load-balancer health checks).

## Writing a custom check

```php
namespace App\Health;

use Marrow\Health\HealthCheck;
use Marrow\Health\HealthResult;

class QueueDepthHealthCheck implements HealthCheck
{
    public function __construct(private readonly \Marrow\Queue\QueueManager $queue) {}

    public function name(): string
    {
        return 'queue';
    }

    public function run(): HealthResult
    {
        $depth = $this->queue->size();
        return $depth > 1000
            ? HealthResult::warn("Queue backlog: {$depth}", ['depth' => $depth])
            : HealthResult::ok('OK', ['depth' => $depth]);
    }
}
```

Register it from a module's `boot()`:

```php
$this->container->make(\Marrow\Health\HealthManager::class)
    ->register(new \App\Health\QueueDepthHealthCheck($queueManager));
```

A check that throws is caught by `HealthManager::report()` itself and
recorded as `failed` ("Check threw: {message}") — a broken check can never
crash the `/health` endpoint. Keep checks fast and side-effect free; they
run on every hit to the endpoint.
