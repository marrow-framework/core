<?php

declare(strict_types=1);

namespace Marrow\Health\Checks;

use Marrow\Health\HealthCheck;
use Marrow\Health\HealthResult;

/**
 * Warns/fails when free disk space on the storage path drops below thresholds.
 */
class DiskSpaceHealthCheck implements HealthCheck
{
    public function __construct(
        private readonly string $path,
        private readonly float $warnPercent = 85.0,
        private readonly float $failPercent = 95.0
    ) {
    }

    public function name(): string
    {
        return 'disk';
    }

    public function run(): HealthResult
    {
        $total = @disk_total_space($this->path);
        $free  = @disk_free_space($this->path);

        if ($total === false || $free === false || $total <= 0) {
            return HealthResult::warn('Could not read disk metrics for ' . $this->path);
        }

        $usedPercent = round((1 - $free / $total) * 100, 1);
        $meta = [
            'used_percent' => $usedPercent,
            'free_bytes'   => (int) $free,
            'total_bytes'  => (int) $total,
        ];

        if ($usedPercent >= $this->failPercent) {
            return HealthResult::fail("Disk {$usedPercent}% full", $meta);
        }
        if ($usedPercent >= $this->warnPercent) {
            return HealthResult::warn("Disk {$usedPercent}% full", $meta);
        }

        return HealthResult::ok("Disk {$usedPercent}% full", $meta);
    }
}
