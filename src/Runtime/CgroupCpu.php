<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime;

use Codinglabs\Yolo\Runtime\Contracts\Cpu;

/**
 * Reads CPU usage from the cgroup v2 interface Fargate mounts — `cpu.stat`'s
 * cumulative `usage_usec` and `cpu.max`'s quota/period (the task's allocated cores).
 * Pure local file reads, so the worker can take a reading even when the box is too
 * pinned to answer the metrics endpoint. Returns null when the files aren't present
 * (cgroup v1, or an unlimited `cpu.max`) — the reporter then simply can't corroborate
 * a scrape failure and stays silent, leaving warm capacity as the guarantee.
 */
class CgroupCpu implements Cpu
{
    public function __construct(
        private readonly string $statPath = '/sys/fs/cgroup/cpu.stat',
        private readonly string $maxPath = '/sys/fs/cgroup/cpu.max',
    ) {}

    public function snapshot(): ?CpuSnapshot
    {
        $usageMicros = $this->usageMicros();
        $cores = $this->cores();

        if ($usageMicros === null || $cores === null) {
            return null;
        }

        return new CpuSnapshot($usageMicros, (int) (microtime(true) * 1_000_000), $cores);
    }

    private function usageMicros(): ?int
    {
        $stat = @file_get_contents($this->statPath);

        if ($stat === false || preg_match('/^usage_usec\s+(\d+)/m', $stat, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function cores(): ?float
    {
        $max = @file_get_contents($this->maxPath);

        if ($max === false) {
            return null;
        }

        $parts = preg_split('/\s+/', trim($max)) ?: [];

        // "max <period>" means unlimited — there's no allocation to take a percentage
        // of, so the CPU fallback doesn't apply.
        if (count($parts) < 2 || $parts[0] === 'max') {
            return null;
        }

        $period = (float) $parts[1];

        return $period > 0.0 ? (float) $parts[0] / $period : null;
    }
}
