<?php

declare(strict_types=1);

namespace Marrow\Health\Checks;

use Marrow\Database\Connection;
use Marrow\Health\HealthCheck;
use Marrow\Health\HealthResult;

/**
 * Warns/fails when the queue backlog or the failed-jobs count grows past
 * configurable thresholds — catches a stuck/dead queue worker before it
 * becomes an outage nobody noticed.
 *
 * Reads the same table names QueueManager uses (config/queue.php), via raw
 * SQL rather than QueueManager itself so this check has no dependency on
 * a worker or job classes being autoloadable — just the two tables existing.
 */
class QueueHealthCheck implements HealthCheck
{
    public function __construct(
        private readonly Connection $db,
        private readonly string $table = 'jobs',
        private readonly string $failedTable = 'failed_jobs',
        private readonly int $backlogWarn = 100,
        private readonly int $backlogFail = 1000,
        private readonly int $failedWarn = 1,
        private readonly int $failedFail = 50,
    ) {
    }

    public function name(): string
    {
        return 'queue';
    }

    public function run(): HealthResult
    {
        try {
            $pending = (int) ($this->db->selectOne("SELECT COUNT(*) AS cnt FROM {$this->table}")['cnt'] ?? 0);
            $failed  = (int) ($this->db->selectOne("SELECT COUNT(*) AS cnt FROM {$this->failedTable}")['cnt'] ?? 0);
        } catch (\Throwable $e) {
            // Missing tables (queue not migrated yet) shouldn't fail the whole
            // app's health report — this check simply has nothing to report.
            return HealthResult::warn('Queue tables not available: ' . $e->getMessage());
        }

        $meta = ['pending' => $pending, 'failed' => $failed];

        if ($pending >= $this->backlogFail || $failed >= $this->failedFail) {
            return HealthResult::fail("Queue backlog {$pending}, {$failed} failed job(s)", $meta);
        }
        if ($pending >= $this->backlogWarn || $failed >= $this->failedWarn) {
            return HealthResult::warn("Queue backlog {$pending}, {$failed} failed job(s)", $meta);
        }

        return HealthResult::ok("Queue backlog {$pending}, {$failed} failed job(s)", $meta);
    }
}
