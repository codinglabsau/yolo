<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime\Contracts;

use Codinglabs\Yolo\Runtime\CpuSnapshot;

/**
 * The seam lets the reporter be tested without a real cgroup.
 */
interface Cpu
{
    public function snapshot(): ?CpuSnapshot;
}
