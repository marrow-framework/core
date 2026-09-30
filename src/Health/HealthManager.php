<?php

declare(strict_types=1);

namespace Marrow\Health;

/**
 * Aggregates health checks and produces an overall report.
 *
 * Register checks in a module boot() or config; expose via a route:
 *
 *   $router->get('/health', fn() => json($health->report()));
 *
 * Overall status is the worst individual status (failed > warning > ok).
 */
class HealthManager
{
    /** @var HealthCheck[] */
    private array $checks = [];

    public function register(HealthCheck $check): void
    {
        $this->checks[$check->name()] = $check;
    }

    /** @return array{status:string, checks:array<string,array>, duration_ms:float} */
    public function report(): array
    {
        $start = microtime(true);
        $results = [];
        $overall = HealthResult::OK;

        foreach ($this->checks as $name => $check) {
            $checkStart = microtime(true);
            try {
                $result = $check->run();
            } catch (\Throwable $e) {
                $result = HealthResult::fail('Check threw: ' . $e->getMessage());
            }

            // Measured here, centrally, rather than by each check — every
            // check (including third-party ones that don't self-time) gets
            // per-check timing for free, useful to tell "the report is slow"
            // apart from "this one probe is slow".
            $results[$name] = $result->toArray() + [
                'duration_ms' => round((microtime(true) - $checkStart) * 1000, 2),
            ];
            $overall = $this->worst($overall, $result->status);
        }

        return [
            'status'      => $overall,
            'checks'      => $results,
            'duration_ms' => round((microtime(true) - $start) * 1000, 2),
        ];
    }

    public function isHealthy(): bool
    {
        return $this->report()['status'] !== HealthResult::FAIL;
    }

    private function worst(string $a, string $b): string
    {
        $rank = [HealthResult::OK => 0, HealthResult::WARN => 1, HealthResult::FAIL => 2];
        return ($rank[$b] ?? 0) > ($rank[$a] ?? 0) ? $b : $a;
    }
}
