<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime;

final readonly class CpuSnapshot
{
    public function __construct(
        public int $usageMicros,
        public int $atMicros,
        public float $cores,
    ) {}
}
