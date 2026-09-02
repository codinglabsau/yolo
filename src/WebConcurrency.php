<?php

declare(strict_types=1);

namespace Codinglabs\Yolo;

/**
 * How many requests one web task can serve at once, resolved for whichever serving
 * model the tier runs — the single number
 * {@see Resources\ApplicationAutoScaling\WebConcurrencyPolicy} holds at a utilisation
 * fraction to decide when to add a task.
 *
 * The two models arrive at that ceiling differently and the numbers are not
 * interchangeable. Octane's worker pool is resident and fixed at boot, so the pool
 * *is* the ceiling ({@see WebWorkers}). Classic mode spawns threads on demand, so its
 * ceiling is the maximum it may grow to, not the floor it starts at
 * ({@see WebThreads}). Resolving it here keeps the scaling signal honest about the
 * capacity the task actually runs: reading the Octane pool on a classic tier would
 * target-track a ceiling that doesn't exist, and reading the classic floor would
 * scale out against a fraction of the real one. The burst path divides by the same
 * ceiling in both modes — see {@see Runtime\WorkerSaturationReporter}.
 */
final class WebConcurrency
{
    /**
     * The per-task concurrency ceiling: the resident worker pool under Octane, the
     * thread autoscaler's maximum in classic mode.
     */
    public static function ceiling(): int
    {
        return Manifest::usesOctane()
            ? WebWorkers::count()
            : WebThreads::maximum();
    }
}
