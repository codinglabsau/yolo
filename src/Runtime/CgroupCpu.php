<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime;

use Codinglabs\Yolo\Runtime\Contracts\Cpu;

/**
 * Reads the task's CPU usage from cgroup, preferring the v2 unified interface
 * (`cpu.stat`'s cumulative `usage_usec`, the AL2023 host default) and falling back to
 * the v1 hierarchy (`cpuacct.usage`, the older AL2 host) — so the numerator is correct
 * whichever kernel Fargate scheduled the task onto. Pure local file reads, so the worker
 * can take a reading even when the box is too pinned to answer the metrics endpoint.
 *
 * The denominator — the task's allocated cores — is the injected allocation
 * (`$allocatedCores`, from YOLO_BURST_CPU) when present, because the Fargate microVM
 * exposes more vCPUs than a fractional task is throttled to: a percent-of-visible-cores
 * reading caps below the allocation and never trips. The manifest knows the real
 * allocation, so YOLO injects it; with no injected value it falls back to the cgroup
 * CFS quota (`cpu.max` v2 / `cpu.cfs_quota_us` v1).
 *
 * Returns null when usage can't be read, or there's no allocation to take a percentage
 * of (unlimited quota and nothing injected) — the reporter then can't corroborate a
 * scrape failure and stays silent, leaving warm capacity as the guarantee.
 */
class CgroupCpu implements Cpu
{
    public function __construct(
        // The task's vCPU allocation, injected from the manifest at deploy time
        // (YOLO_BURST_CPU). Authoritative and immune to the microVM reporting more
        // vCPUs than the task is throttled to; 0.0 means "not injected — read the quota".
        private readonly float $allocatedCores = 0.0,
        // cgroup v2 (unified) — the AL2023 host default.
        private readonly string $v2StatPath = '/sys/fs/cgroup/cpu.stat',
        private readonly string $v2MaxPath = '/sys/fs/cgroup/cpu.max',
        // cgroup v1 (hybrid) — the older AL2 host; cpu/cpuacct reached via systemd's symlinks.
        private readonly string $v1UsagePath = '/sys/fs/cgroup/cpuacct/cpuacct.usage',
        private readonly string $v1QuotaPath = '/sys/fs/cgroup/cpu/cpu.cfs_quota_us',
        private readonly string $v1PeriodPath = '/sys/fs/cgroup/cpu/cpu.cfs_period_us',
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

    /** Cumulative CPU time used by the task, in microseconds — cgroup v2, then v1. */
    private function usageMicros(): ?int
    {
        return $this->v2UsageMicros() ?? $this->v1UsageMicros();
    }

    /** The task's allocated cores — the injected allocation, else the cgroup CFS quota. */
    private function cores(): ?float
    {
        if ($this->allocatedCores > 0.0) {
            return $this->allocatedCores;
        }

        return $this->v2Cores() ?? $this->v1Cores();
    }

    private function v2UsageMicros(): ?int
    {
        $stat = @file_get_contents($this->v2StatPath);

        if ($stat === false || preg_match('/^usage_usec\s+(\d+)/m', $stat, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function v2Cores(): ?float
    {
        $max = @file_get_contents($this->v2MaxPath);

        if ($max === false) {
            return null;
        }

        $parts = preg_split('/\s+/', trim($max)) ?: [];

        // "max <period>" means unlimited — there's no allocation to take a percentage of.
        if (count($parts) < 2 || $parts[0] === 'max') {
            return null;
        }

        $period = (float) $parts[1];

        return $period > 0.0 ? (float) $parts[0] / $period : null;
    }

    private function v1UsageMicros(): ?int
    {
        $usageNanos = @file_get_contents($this->v1UsagePath);

        if ($usageNanos === false) {
            return null;
        }

        $usageNanos = trim($usageNanos);

        if (! is_numeric($usageNanos)) {
            return null;
        }

        // cpuacct.usage is nanoseconds; v2's usage_usec is micros — normalise to micros.
        return intdiv((int) $usageNanos, 1_000);
    }

    private function v1Cores(): ?float
    {
        $quota = @file_get_contents($this->v1QuotaPath);
        $period = @file_get_contents($this->v1PeriodPath);

        if ($quota === false || $period === false) {
            return null;
        }

        $quotaMicros = (float) trim($quota);
        $periodMicros = (float) trim($period);

        // quota -1 means unlimited — the same "no allocation" case as v2's "max".
        if ($quotaMicros < 0.0 || $periodMicros <= 0.0) {
            return null;
        }

        return $quotaMicros / $periodMicros;
    }
}
