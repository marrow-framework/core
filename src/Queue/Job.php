<?php

declare(strict_types=1);

namespace Marrow\Queue;

/**
 * Base class for queued jobs. Subclass and implement handle().
 *
 *   class SendWelcomeEmail extends Job
 *   {
 *       public function __construct(private int $userId) {}
 *
 *       public function handle(): void
 *       {
 *           $user = User::find($this->userId);
 *           // Jobs are unserialized from the queue, not container-resolved,
 *           // so constructor injection isn't available here — resolve
 *           // services explicitly instead:
 *           app(Mailer::class)->send(new WelcomeMail($user));
 *       }
 *   }
 *
 *   // Dispatch (from a controller/service, via injected QueueManager):
 *   $queue->push(new SendWelcomeEmail($user->id));
 *   $queue->later(60, new SendWelcomeEmail($user->id));  // 60s delay
 *
 * Jobs are serialized with PHP's serialize(); keep constructor args to scalars
 * or ids rather than full models so the payload stays small and stable.
 */
abstract class Job
{
    /** Max times this job may be attempted before being marked failed. */
    public int $tries = 3;

    /** Seconds to wait before a retry after a failure. */
    public int $retryAfter = 60;

    /** Target queue name. */
    public string $queue = 'default';

    abstract public function handle(): void;

    /** Called when the job has exhausted all attempts. Override to react. */
    public function failed(\Throwable $e): void
    {
    }

    public function onQueue(string $queue): static
    {
        $this->queue = $queue;
        return $this;
    }
}
