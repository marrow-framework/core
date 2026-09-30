<?php

declare(strict_types=1);

namespace Marrow\Health;

/**
 * Contract for a single health probe. Implementations should be fast and
 * side-effect free; they run on every hit to the /health endpoint.
 */
interface HealthCheck
{
    /** Short identifier, e.g. "database", "cache", "disk". */
    public function name(): string;

    public function run(): HealthResult;
}
