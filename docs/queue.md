# Queues & Jobs

A single, database-backed queue (`Marrow\Queue\QueueManager`) — no Redis
or external broker required to get started.

## Defining a job

```php
namespace App\Jobs;

use Marrow\Queue\Job;

class SendWelcomeEmail extends Job
{
    public int $tries = 3;
    public int $retryAfter = 60;    // seconds before a retry after failure
    public string $queue = 'default';

    public function __construct(private readonly int $userId) {}

    public function handle(): void
    {
        // Jobs are unserialized from the DB, not container-resolved — no
        // constructor injection here. Resolve services explicitly:
        $user = User::find($this->userId);
        app(\Marrow\Mail\Mailer::class)->send(new WelcomeMail($user));
    }

    public function failed(\Throwable $e): void
    {
        // called once tries are exhausted
    }
}
```

Keep constructor arguments to scalars/ids, not full model instances — jobs
are serialized with PHP's `serialize()` into the `jobs` table, so the
payload should stay small and stable across deploys.

## Dispatching

```php
dispatch(new SendWelcomeEmail($user->id));                     // global helper
app(\Marrow\Queue\QueueManager::class)->push(new SendWelcomeEmail($user->id));
app(\Marrow\Queue\QueueManager::class)->later(60, new SendWelcomeEmail($user->id)); // delayed 60s

$job = new SendWelcomeEmail($user->id);
$job->onQueue('emails');
dispatch($job);
```

## Running the worker

```bash
php forge queue:work                    # loop forever
php forge queue:work --queue=emails --sleep=3 --once
```

`Worker::work()` reserves the next available job (`available_at <= now`,
`reserved_at IS NULL`, oldest first, via a transaction so two workers never
grab the same row), runs `handle()`, and:

- **success** → deletes the row.
- **failure, attempts < tries** → releases it back with `retryAfter` delay.
- **failure, attempts >= tries** → moves it to `failed_jobs` and calls
  `failed($e)` (swallowing any exception `failed()` itself throws, so a
  broken failure-handler can't crash the worker).

`SIGTERM`/`SIGINT` are handled gracefully (via `pcntl`, when available) so
`--once`-less workers stop cleanly between jobs rather than mid-`handle()`.
After every job — success or failure — the container's module-resolution
context is reset (`Container::resetModuleContext()`) so a failure resolving
a module-scoped dependency in one job can never leak into the next.

## Security note: job (de)serialization

`QueueManager` restricts `unserialize()` to `Job`/subclasses of `Job` only
(`allowed_classes`) — this blocks PHP object-injection via arbitrary gadget
classes even if the `jobs` table were ever reachable by something other
than `push()`/`later()` (a separate SQL injection, direct DB access, etc.).
Don't rely on custom `__wakeup()`/`__destruct()` side effects in a `Job`
subclass for anything security-sensitive.

## Tables

`jobs` (`id`, `queue`, `payload`, `attempts`, `reserved_at`, `available_at`,
`created_at`) and `failed_jobs` (`id`, `queue`, `payload`, `exception`,
`failed_at`) — created by the skeleton's migration. `available_at`/
`created_at`/`failed_at` are plain Unix timestamps (PHP `time()`), not SQL
`DATETIME` columns — match that in a migration if you add columns of your
own to either table.

Table names are configurable:

```php
// config/queue.php
return ['table' => 'jobs', 'failed_table' => 'failed_jobs'];
```
