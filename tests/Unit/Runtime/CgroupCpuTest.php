<?php

declare(strict_types=1);

use Codinglabs\Yolo\Runtime\CgroupCpu;

/** Writes the named cgroup files into a fresh temp dir and returns their paths. */
function cgroupFiles(array $files): array
{
    $dir = sys_get_temp_dir() . '/yolo-cgroup-' . uniqid();
    mkdir($dir);

    $paths = [];

    foreach ($files as $name => $contents) {
        $paths[$name] = "$dir/$name";
        file_put_contents($paths[$name], $contents);
    }

    return $paths;
}

const MISSING = '/nonexistent/yolo-cgroup-path';

it('reads usage and cores from the cgroup v2 unified interface', function (): void {
    $files = cgroupFiles([
        'cpu.stat' => "usage_usec 200000\nuser_usec 120000\nsystem_usec 80000\n",
        'cpu.max' => "50000 100000\n",
    ]);

    $snapshot = (new CgroupCpu(v2StatPath: $files['cpu.stat'], v2MaxPath: $files['cpu.max']))->snapshot();

    expect($snapshot->usageMicros)->toBe(200000)
        ->and($snapshot->cores)->toBe(0.5);
});

it('falls back to the cgroup v1 hierarchy when the v2 files are absent', function (): void {
    $files = cgroupFiles([
        'cpuacct.usage' => "200000000\n",   // nanoseconds → 200000 µs
        'cfs_quota_us' => "50000\n",
        'cfs_period_us' => "100000\n",
    ]);

    $snapshot = (new CgroupCpu(
        v2StatPath: MISSING,
        v2MaxPath: MISSING,
        v1UsagePath: $files['cpuacct.usage'],
        v1QuotaPath: $files['cfs_quota_us'],
        v1PeriodPath: $files['cfs_period_us'],
    ))->snapshot();

    expect($snapshot->usageMicros)->toBe(200000)   // nanos normalised to micros
        ->and($snapshot->cores)->toBe(0.5);        // 50000 / 100000
});

it('prefers the injected allocation over an unlimited cgroup quota', function (): void {
    // The microVM case: usage reads fine, but cpu.max is "max" (the container can't see
    // its CFS quota) — without the injected allocation cores would be null and the
    // snapshot lost. The injected 0.5 is the real allocation.
    $files = cgroupFiles([
        'cpu.stat' => "usage_usec 200000\n",
        'cpu.max' => "max 100000\n",
    ]);

    $snapshot = (new CgroupCpu(
        allocatedCores: 0.5,
        v2StatPath: $files['cpu.stat'],
        v2MaxPath: $files['cpu.max'],
    ))->snapshot();

    expect($snapshot->cores)->toBe(0.5)
        ->and($snapshot->usageMicros)->toBe(200000);
});

it('returns null when usage cannot be read from either hierarchy', function (): void {
    $snapshot = (new CgroupCpu(
        allocatedCores: 0.5,
        v2StatPath: MISSING,
        v2MaxPath: MISSING,
        v1UsagePath: MISSING,
    ))->snapshot();

    expect($snapshot)->toBeNull();
});

it('returns null when the quota is unlimited and nothing was injected', function (): void {
    // Usage is present but there's no allocation to take a percentage of — fail safe
    // to silence, leaving warm capacity as the guarantee.
    $files = cgroupFiles([
        'cpu.stat' => "usage_usec 200000\n",
        'cpu.max' => "max 100000\n",
    ]);

    $snapshot = (new CgroupCpu(
        v2StatPath: $files['cpu.stat'],
        v2MaxPath: $files['cpu.max'],
        v1QuotaPath: MISSING,
        v1PeriodPath: MISSING,
    ))->snapshot();

    expect($snapshot)->toBeNull();
});
