<?php

declare(strict_types=1);

namespace Codinglabs\Yolo;

/**
 * How many requests one web task can serve at once — the number
 * {@see Resources\ApplicationAutoScaling\WebConcurrencyPolicy} holds at a utilisation fraction,
 * and the ceiling the burst path divides by in both modes ({@see Runtime\WorkerSaturationReporter}).
 * The two serving models aren't interchangeable: Octane's resident pool IS the ceiling
 * ({@see WebWorkers}); classic mode's ceiling is the maximum its threads may grow to, not the
 * floor ({@see WebThreads}). Reading the wrong one would target-track a ceiling that doesn't
 * exist, or scale out against a fraction of the real one.
 */
final class WebConcurrency
{
    public static function ceiling(): int
    {
        return Manifest::usesOctane()
            ? WebWorkers::count()
            : WebThreads::maximum();
    }
}
