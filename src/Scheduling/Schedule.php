<?php

declare(strict_types=1);

namespace Marrow\Scheduling;

use Marrow\Application;
use Marrow\Queue\Job;
use Marrow\Queue\QueueManager;

/**
 * Task scheduler — define recurring work in one place and trigger it from a
 * single cron entry:
 *
 *   * * * * * cd /path/to/app && php forge schedule:run >> /dev/null 2>&1
 *
 * Define tasks in a module's boot() or a dedicated scheduler file:
 *
 *   $schedule->call(fn() => $cache->flush())->daily();
 *   $schedule->command('db:backup')->dailyAt('02:00')->withoutOverlapping();
 *   $schedule->job(new PruneStaleSessions())->hourly();
 *   $schedule->call($fn)->everyMinutes(15)->weekdays();
 *
 * withoutOverlapping() guards against a run still in progress when the next
 * one is due (e.g. a slow task on a minute-by-minute cron) via a process-level
 * file lock — see ScheduledEvent.
 */
class Schedule
{
    /** @var ScheduledEvent[] */
    private array $events = [];

    public function __construct(
        private readonly Application $app,
        private readonly ?QueueManager $queue = null
    ) {
    }

    /** Schedule a closure to run in-process. */
    public function call(callable $callback, array $args = []): ScheduledEvent
    {
        return $this->events[] = new ScheduledEvent(
            fn() => $callback(...$args),
            'closure'
        );
    }

    /**
     * Schedule a forge console command (run as a subprocess). The callback
     * itself — not command() — throws \RuntimeException on a non-zero exit
     * code; ScheduledEvent::run() catches it (see Schedule::run()).
     */
    public function command(string $command): ScheduledEvent
    {
        return $this->events[] = new ScheduledEvent(
            function () use ($command) {
                $php = PHP_BINARY;
                $forge = $this->app->getBasePath('forge');

                // Escape every token individually — not just $php/$forge — so the
                // command string can never break out of its shell-argument
                // boundary even if it's ever built from something other than a
                // literal string in a module's boot().
                $tokens = preg_split('/\s+/', trim($command)) ?: [];
                $escapedCommand = implode(' ', array_map('escapeshellarg', $tokens));

                $cmd = escapeshellarg($php) . ' ' . escapeshellarg($forge) . ' ' . $escapedCommand;
                exec($cmd, $out, $code);
                if ($code !== 0) {
                    throw new \RuntimeException("Scheduled command '{$command}' exited with code {$code}: " . implode("\n", $out));
                }
            },
            'command:' . $command
        );
    }

    /** Schedule a queued job to be dispatched. */
    public function job(Job $job): ScheduledEvent
    {
        return $this->events[] = new ScheduledEvent(
            function () use ($job) {
                $this->queue?->push($job);
            },
            'job:' . $job::class
        );
    }

    /**
     * Run all events that are due at the given time (defaults to now).
     *
     * @return array<array{event:string, status:string}> Run summary.
     */
    public function run(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $results = [];

        foreach ($this->events as $event) {
            if (!$event->isDue($now)) {
                continue;
            }

            try {
                $ran = $event->run();
                $results[] = ['event' => $event->description(), 'status' => $ran ? 'ok' : 'skipped (overlapping)'];
            } catch (\Throwable $e) {
                $results[] = ['event' => $event->description(), 'status' => 'error: ' . $e->getMessage()];
            }
        }

        return $results;
    }

    /** @return ScheduledEvent[] */
    public function events(): array
    {
        return $this->events;
    }
}
