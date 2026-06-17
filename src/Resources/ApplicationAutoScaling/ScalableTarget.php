<?php

namespace Codinglabs\Yolo\Resources\ApplicationAutoScaling;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Resources\Ecs\EcsCluster;
use Codinglabs\Yolo\Resources\Ecs\EcsService;
use Codinglabs\Yolo\Aws\ApplicationAutoScaling;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * The Application Auto Scaling scalable target that hands an ECS service's
 * desired count to scaling policies. Group-aware: both groups' bounds come from
 * their own `tasks.{group}.autoscaling.min/max` (Manifest::autoscalingMin/Max) —
 * web defaults 1/4 (min always ≥ 1), the queue 1/10 (min may be 0 to scale to zero).
 *
 * Like Dashboard this is a standalone reconciler, NOT a Resource:
 * App Auto Scaling targets aren't RGT-taggable (so they carry none of the
 * ownership tags the Resource contract reconciles, and stay invisible to
 * `yolo audit`) and RegisterScalableTarget is a pure upsert with no create/update
 * split.
 *
 * Dry-run honest — it reads the live min/max, diffs them, and only re-registers
 * on drift, so `sync --dry-run` reports exactly when the capacity bounds change.
 *
 * Registering a target hands desired-count ownership to App Auto Scaling, which
 * is precisely why EcsService leaves desiredCount create-only: sync never fights
 * the scaler for capacity.
 */
class ScalableTarget
{
    public function __construct(protected ServerGroup $group = ServerGroup::WEB) {}

    /**
     * service/{cluster}/{service} — the App Auto Scaling resource id for a group's
     * ECS service.
     */
    public static function resourceId(ServerGroup $group = ServerGroup::WEB): string
    {
        return sprintf('service/%s/%s', (new EcsCluster())->name(), (new EcsService($group))->name());
    }

    public function exists(): bool
    {
        return $this->current() !== null;
    }

    public function min(): int
    {
        return Manifest::autoscalingMin($this->group);
    }

    public function max(): int
    {
        return Manifest::autoscalingMax($this->group);
    }

    /**
     * Diff the live min/max against the manifest and (only on drift, when
     * applying) re-register the target. Returns the drift as Change[] so the sync
     * step reports WOULD_CREATE / WOULD_SYNC / SYNCED and the apply pass survives
     * the only-pending-steps filter.
     *
     * @return array<int, Change>
     */
    public function synchronise(bool $apply): array
    {
        $live = $this->current();
        $min = $this->min();
        $max = $this->max();

        $changes = [];

        if (($live['min'] ?? null) !== $min) {
            $changes[] = Change::make('MinCapacity', $live['min'] ?? null, $min);
        }

        if (($live['max'] ?? null) !== $max) {
            $changes[] = Change::make('MaxCapacity', $live['max'] ?? null, $max);
        }

        if ($changes === [] || ! $apply) {
            return $changes;
        }

        $this->register($min, $max);

        return $changes;
    }

    public function register(int $min, int $max): void
    {
        Aws::applicationAutoScaling()->registerScalableTarget([
            'ServiceNamespace' => ApplicationAutoScaling::SERVICE_NAMESPACE,
            'ResourceId' => static::resourceId($this->group),
            'ScalableDimension' => ApplicationAutoScaling::SCALABLE_DIMENSION,
            'MinCapacity' => $min,
            'MaxCapacity' => $max,
        ]);
    }

    public function deregister(): void
    {
        Aws::applicationAutoScaling()->deregisterScalableTarget([
            'ServiceNamespace' => ApplicationAutoScaling::SERVICE_NAMESPACE,
            'ResourceId' => static::resourceId($this->group),
            'ScalableDimension' => ApplicationAutoScaling::SCALABLE_DIMENSION,
        ]);
    }

    /**
     * The live min/max of the registered target, or null when none is registered.
     *
     * @return array{min: int, max: int}|null
     */
    public function current(): ?array
    {
        try {
            $target = ApplicationAutoScaling::scalableTarget(static::resourceId($this->group));

            return ['min' => (int) $target['MinCapacity'], 'max' => (int) $target['MaxCapacity']];
        } catch (ResourceDoesNotExistException) {
            return null;
        }
    }
}
