<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime;

/**
 * One reading of the container's CPU accounting: cumulative CPU time used, the wall
 * clock it was read at, and the cores the task is allocated — enough for the reporter
 * to compute utilisation as a percentage of the allocation between two snapshots.
 */
final readonly class CpuSnapshot
{
    public function __construct(
        public int $usageMicros,
        public int $atMicros,
        public float $cores,
    ) {}
}
