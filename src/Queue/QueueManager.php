<?php

declare(strict_types=1);

namespace Marrow\Queue;

use Marrow\Database\Connection;

/**
 * Database-backed job queue.
 *
 * Jobs are stored in the `jobs` table (see the queue migration) and processed
 * by a worker started with `php forge queue:work`. Failed jobs land in
 * `failed_jobs` for inspection and replay.
 *
 * For higher throughput you can swap the storage backend (e.g. Redis) without
 * changing the Job API or call sites; this class is the single integration
 * point.
 */
class QueueManager
{
    public function __construct(
        private readonly Connection $db,
        private readonly string $table = 'jobs',
        private readonly string $failedTable = 'failed_jobs'
    ) {
    }

    /** Push a job for immediate processing. */
    public function push(Job $job): int
    {
        return $this->enqueue($job, 0);
    }

    /** Push a job to run after $delaySeconds. */
    public function later(int $delaySeconds, Job $job): int
    {
        return $this->enqueue($job, $delaySeconds);
    }

    private function enqueue(Job $job, int $delaySeconds): int
    {
        return $this->db->insert($this->table, [
            'queue'        => $job->queue,
            'payload'      => serialize($job),
            'attempts'     => 0,
            'available_at' => time() + $delaySeconds,
            'created_at'   => time(),
        ]);
    }

    /**
     * Reserve and return the next available job on the given queue, or null.
     * Uses a transaction + locking update to avoid two workers grabbing the
     * same row.
     *
     * @throws \RuntimeException If the reserved row's payload doesn't unserialize to a Job.
     */
    public function pop(string $queue = 'default'): ?ReservedJob
    {
        return $this->db->transaction(function () use ($queue) {
            $row = $this->db->selectOne(
                "SELECT * FROM {$this->table}
                 WHERE queue = ? AND available_at <= ? AND reserved_at IS NULL
                 ORDER BY id ASC LIMIT 1",
                [$queue, time()]
            );

            if ($row === null) {
                return null;
            }

            $this->db->update(
                $this->table,
                ['reserved_at' => time(), 'attempts' => $row['attempts'] + 1],
                ['id' => $row['id']]
            );

            $job = $this->unserializeJob((string) $row['payload'], (int) $row['id']);

            return new ReservedJob(
                (int) $row['id'],
                $job,
                (int) $row['attempts'] + 1
            );
        });
    }

    /**
     * Unserializes a stored payload restricted to Job subclasses — blocks PHP
     * object injection (arbitrary gadget-chain classes) even if the `jobs`
     * table were ever reachable by something other than enqueue() (e.g. a
     * separate SQL injection, or direct DB access). The class named in the
     * payload is autoloaded explicitly first, since allowed_classes only
     * recognizes classes already declared in this process — get_declared_classes()
     * wouldn't otherwise include a Job subclass no code has referenced yet.
     */
    private function unserializeJob(string $payload, int $id): Job
    {
        if (preg_match('/^O:\d+:"([^"]+)"/', $payload, $m)) {
            class_exists($m[1], true);
        }

        $allowedClasses = array_values(array_filter(
            get_declared_classes(),
            static fn (string $c): bool => $c === Job::class || is_subclass_of($c, Job::class)
        ));

        $job = unserialize($payload, ['allowed_classes' => $allowedClasses]);

        if (!$job instanceof Job) {
            throw new \RuntimeException('Invalid job payload for id ' . $id);
        }

        return $job;
    }

    /** Remove a completed job. */
    public function delete(int $id): void
    {
        $this->db->delete($this->table, ['id' => $id]);
    }

    /** Release a job back to the queue for a later retry. */
    public function release(ReservedJob $job, int $delaySeconds): void
    {
        $this->db->update(
            $this->table,
            ['reserved_at' => null, 'available_at' => time() + $delaySeconds],
            ['id' => $job->id]
        );
    }

    /** Move a job to the failed_jobs table. */
    public function markFailed(ReservedJob $job, \Throwable $e): void
    {
        $this->db->insert($this->failedTable, [
            'queue'      => $job->job->queue,
            'payload'    => serialize($job->job),
            'exception'  => (string) $e,
            'failed_at'  => time(),
        ]);
        $this->delete($job->id);
    }

    public function size(string $queue = 'default'): int
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS cnt FROM {$this->table} WHERE queue = ?",
            [$queue]
        );
        return (int) ($row['cnt'] ?? 0);
    }
}
