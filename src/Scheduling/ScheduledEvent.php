<?php

declare(strict_types=1);

namespace Marrow\Scheduling;

/**
 * A single scheduled task with a cron expression and run filters.
 * Returned by Schedule::call/command/job — chain frequency helpers on it.
 */
class ScheduledEvent
{
    /** Standard 5-field cron expression: minute hour day-of-month month day-of-week */
    private string $expression = '* * * * *';

    /** @var callable[] Extra predicates that must all pass for the event to run. */
    private array $filters = [];

    private ?string $description = null;

    private bool $withoutOverlapping = false;

    /** @var resource|null */
    private $lockHandle = null;

    public function __construct(
        private readonly \Closure $callback,
        private readonly string $defaultDescription
    ) {
    }

    /**
     * Skip this run if a previous run of the same event is still in progress,
     * instead of starting a second one alongside it (e.g. a task that can take
     * longer than the interval it's scheduled on). Backed by an OS-level file
     * lock (flock) scoped to this event's description, so a crashed or killed
     * process releases it automatically — no manual staleness/expiry needed.
     */
    public function withoutOverlapping(): self
    {
        $this->withoutOverlapping = true;
        return $this;
    }

    /** @return bool True if the event actually ran; false if skipped (overlapping run in progress). */
    public function run(): bool
    {
        if (!$this->withoutOverlapping) {
            ($this->callback)();
            return true;
        }

        if (!$this->acquireLock()) {
            return false;
        }

        try {
            ($this->callback)();
            return true;
        } finally {
            $this->releaseLock();
        }
    }

    private function acquireLock(): bool
    {
        $handle = fopen($this->lockPath(), 'c');
        if ($handle === false) {
            return false;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        $this->lockHandle = $handle;
        return true;
    }

    private function releaseLock(): void
    {
        if ($this->lockHandle !== null) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    private function lockPath(): string
    {
        return sys_get_temp_dir() . '/marrow-schedule-' . sha1($this->defaultDescription) . '.lock';
    }

    // ── Frequency helpers ────────────────────────────────────────────

    public function cron(string $expression): self
    {
        $this->expression = $expression;
        return $this;
    }

    public function everyMinute(): self
    {
        return $this->cron('* * * * *');
    }

    public function everyMinutes(int $n): self
    {
        return $this->cron("*/{$n} * * * *");
    }

    public function hourly(): self
    {
        return $this->cron('0 * * * *');
    }

    public function hourlyAt(int $minute): self
    {
        return $this->cron("{$minute} * * * *");
    }

    public function daily(): self
    {
        return $this->cron('0 0 * * *');
    }

    public function dailyAt(string $time): self
    {
        [$h, $m] = array_pad(explode(':', $time), 2, '0');
        return $this->cron(((int) $m) . ' ' . ((int) $h) . ' * * *');
    }

    public function weekly(): self
    {
        return $this->cron('0 0 * * 0');
    }

    public function monthly(): self
    {
        return $this->cron('0 0 1 * *');
    }

    public function weekdays(): self
    {
        $this->filters[] = fn(\DateTimeImmutable $now) => (int) $now->format('N') <= 5;
        return $this;
    }

    public function weekends(): self
    {
        $this->filters[] = fn(\DateTimeImmutable $now) => (int) $now->format('N') >= 6;
        return $this;
    }

    /** Only run when the predicate returns true. */
    public function when(callable $predicate): self
    {
        $this->filters[] = fn() => (bool) $predicate();
        return $this;
    }

    public function description(?string $text = null): string
    {
        if ($text !== null) {
            $this->description = $text;
            return $text;
        }
        return $this->description ?? $this->defaultDescription;
    }

    public function name(string $text): self
    {
        $this->description = $text;
        return $this;
    }

    // ── Due-time evaluation ──────────────────────────────────────────

    public function isDue(\DateTimeImmutable $now): bool
    {
        if (!$this->cronMatches($now)) {
            return false;
        }
        foreach ($this->filters as $filter) {
            if (!$filter($now)) {
                return false;
            }
        }
        return true;
    }

    /** Minimal 5-field cron matcher supporting *, lists, ranges and steps. */
    private function cronMatches(\DateTimeImmutable $now): bool
    {
        [$min, $hour, $dom, $mon, $dow] = array_pad(
            preg_split('/\s+/', trim($this->expression)) ?: [],
            5,
            '*'
        );

        $dayMatch = ($dom !== '*' && $dow !== '*')
            ? ($this->fieldMatches($dom, (int) $now->format('j')) || $this->fieldMatches($dow, (int) $now->format('w')))
            : ($this->fieldMatches($dom, (int) $now->format('j')) && $this->fieldMatches($dow, (int) $now->format('w')));

        return $this->fieldMatches($min, (int) $now->format('i'))
            && $this->fieldMatches($hour, (int) $now->format('G'))
            && $this->fieldMatches($mon, (int) $now->format('n'))
            && $dayMatch;
    }

    private function fieldMatches(string $field, int $value): bool
    {
        foreach (explode(',', $field) as $part) {
            if ($part === '*') {
                return true;
            }

            // Step: */5 or 10-30/5
            $step = 1;
            if (str_contains($part, '/')) {
                [$part, $stepStr] = explode('/', $part, 2);
                $step = max(1, (int) $stepStr);
                $part = $part === '*' ? '0-59' : $part;
            }

            // Range: 10-30
            if (str_contains($part, '-')) {
                [$lo, $hi] = array_map('intval', explode('-', $part, 2));
                if ($value >= $lo && $value <= $hi && (($value - $lo) % $step) === 0) {
                    return true;
                }
                continue;
            }

            if ((int) $part === $value) {
                return true;
            }
        }

        return false;
    }
}
