<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime\Contracts;

use Codinglabs\Yolo\Runtime\CpuSnapshot;

/**
 * Reads the container's cumulative CPU usage. The reporter deltas two snapshots to
 * derive utilisation as a fallback breach signal when the metrics scrape fails — a
 * local read the worker can always do, independent of the (possibly starved) Caddy
 * admin endpoint. The seam lets the reporter be tested without a real cgroup.
 */
interface Cpu
{
    public function snapshot(): ?CpuSnapshot;
}
