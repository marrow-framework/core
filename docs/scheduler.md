# Task Scheduling

`Marrow\Scheduling\Schedule` lets you define every recurring task in one
place, triggered by a **single** cron entry instead of one crontab line per
task.

## One cron entry

```
* * * * * cd /path/to/app && php forge schedule:run >> /dev/null 2>&1
```

`schedule:run` evaluates every registered event's cron expression against
the current minute and runs whichever ones are due.

## Defining scheduled tasks

Register events from a module's `boot()` (the shared `Schedule` instance is
a container singleton, so anything registered during boot survives to when
`schedule:run` evaluates it):

```php
class ReportingModule extends BaseModule
{
    public function boot(): void
    {
        $schedule = $this->container->make(\Marrow\Scheduling\Schedule::class);

        $schedule->call(fn () => Cache::flush())->daily();
        $schedule->command('reports:generate')->dailyAt('02:00')->withoutOverlapping();
        $schedule->job(new PruneStaleSessions())->hourly();
        $schedule->call($fn, [$arg1, $arg2])->everyMinutes(15)->weekdays();
    }
}
```

Three kinds of scheduled work:

- `call(callable $callback, array $args = [])` — runs in-process.
- `command(string $command)` — runs `php forge {$command}` as a subprocess
  (every token is escaped individually — safe even if the string is ever
  built from something other than a literal); throws if the subprocess
  exits non-zero.
- `job(Job $job)` — pushes the job onto the queue rather than running it
  inline (see [Queues & Jobs](queue.md)).

## Frequency helpers

`cron('* * * * *')` (raw 5-field expression — `*`, lists, ranges, and
`*/n` steps are supported), `everyMinute()`, `everyMinutes(n)`, `hourly()`,
`hourlyAt(minute)`, `daily()`, `dailyAt('02:30')`, `weekly()`, `monthly()`,
`weekdays()`, `weekends()`, `when(callable $predicate)` (only runs if it
returns true).

## Preventing overlap

```php
$schedule->command('reports:generate')->withoutOverlapping();
```

Backed by an OS-level file lock (`flock`, scoped by a hash of the event's
description) rather than a stored "last run" timestamp — so a crashed or
killed process releases the lock automatically, with no manual
staleness/expiry handling needed. If a previous run is still holding the
lock when the next one comes due, that run is **skipped**, not queued.

## Naming events

```php
$schedule->command('reports:generate')->name('nightly-report');
```

Shows up as the event's description in `schedule:run`'s output summary
(`['event' => ..., 'status' => 'ok'|'skipped (overlapping)'|'error: ...']`)
— give overlap-guarded events a stable name so the lock file (and the
output) stays meaningful across deploys.

## Running manually / testing

```php
$schedule->run(new \DateTimeImmutable('2024-06-01 02:00:00')); // evaluate against an explicit time
```

useful in a test to assert a given event *would* run at a specific moment
without waiting for real time to pass.
