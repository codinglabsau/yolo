<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\ApplicationAutoScaling;

use Codinglabs\Yolo\Change;

/**
 * Lets SyncScalingPoliciesStep reconcile a heterogeneous set of web policies through
 * one loop. PutScalingPolicy is a pure upsert (no create/update split): synchronise()
 * diffs the live policy, returns the drift so the plan pass can report WOULD_*, and
 * only writes on drift.
 */
interface TargetTrackingPolicy
{
    public function exists(): bool;

    /**
     * @return array<int, Change>
     */
    public function synchronise(bool $apply): array;
}
