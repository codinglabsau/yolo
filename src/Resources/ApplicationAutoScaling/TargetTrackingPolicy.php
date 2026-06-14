<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\ApplicationAutoScaling;

use Codinglabs\Yolo\Change;

/**
 * A target-tracking scaling policy on the web service's scalable target. Both the
 * CPU policy ({@see ScalingPolicy}) and the request-concurrency policy
 * ({@see WebConcurrencyPolicy}) implement this so SyncScalingPoliciesStep can
 * reconcile a heterogeneous set of them through one loop. PutScalingPolicy is a
 * pure upsert, so there's no create/update split — synchronise() reads the live
 * policy, diffs it, and only writes on drift.
 */
interface TargetTrackingPolicy
{
    public function exists(): bool;

    /**
     * Diff the live policy against the desired config and (only on drift, when
     * applying) upsert it. Returns the drift as Change[].
     *
     * @return array<int, Change>
     */
    public function synchronise(bool $apply): array;
}
