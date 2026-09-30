<?php

declare(strict_types=1);

namespace Marrow\Queue;

use Marrow\Container;
use Psr\Log\LoggerInterface;

/**
 * Processes queued jobs in a long-running loop.
 *
 * Driven by `php forge queue:work [--queue=default] [--once] [--sleep=3]`.
 *
 * Each job is attempted up to $job->tries times; on the final failure it is
 * moved to failed_jobs and Job::failed() is invoked.
 */
class Worker
{
    private bool $shouldStop = false;

    public function __construct(
        private readonly QueueManager $queue,
        private readonly LoggerInterface $logger,
        private readonly Container $container
    ) {
    }

    /**
     * @param callable|null $onProcessed Invoked after each job with a status
     *        string ('done' | 'released' | 'failed' | 'idle') for CLI output.
     */
    public function work(
        string $queue = 'default',
        int $sleep = 3,
        bool $once = false,
        ?callable $onProcessed = null
    ): void {
        $this->registerSignals();

        while (!$this->shouldStop) {
            $reserved = $this->queue->pop($queue);

            if ($reserved === null) {
                if ($once) {
                    $onProcessed && $onProcessed('idle', null);
                    return;
                }
                sleep($sleep);
                continue;
            }

            $status = $this->process($reserved);
            $onProcessed && $onProcessed($status, $reserved);

            if ($once) {
                return;
            }
        }
    }

    private function process(ReservedJob $reserved): string
    {
        $job = $reserved->job;

        try {
            $job->handle();
            $this->queue->delete($reserved->id);
            return 'done';
        } catch (\Throwable $e) {
            $this->logger->error('Queue job failed', [
                'job'      => $job::class,
                'attempts' => $reserved->attempts,
                'error'    => $e->getMessage(),
            ]);

            if ($reserved->attempts >= $job->tries) {
                $this->queue->markFailed($reserved, $e);
                try {
                    $job->failed($e);
                } catch (\Throwable) {
                    // Swallow — failed() handlers must not crash the worker.
                }
                return 'failed';
            }

            $this->queue->release($reserved, $job->retryAfter);
            return 'released';
        } finally {
            // Belt-and-braces: a job that throws mid-resolution of a
            // module-scoped dependency must never leak that module context
            // into the next job processed by this same long-running worker.
            $this->container->resetModuleContext();
        }
    }

    public function stop(): void
    {
        $this->shouldStop = true;
    }

    private function registerSignals(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn() => $this->stop());
            pcntl_signal(SIGINT, fn() => $this->stop());
        }
    }
}
