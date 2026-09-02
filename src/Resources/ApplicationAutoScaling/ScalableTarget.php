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
 * Not a Resource: App Auto Scaling targets aren't RGT-taggable (so invisible to
 * `yolo audit`) and RegisterScalableTarget is a pure upsert. Registering hands
 * desired-count ownership to App Auto Scaling — which is why EcsService leaves
 * desiredCount create-only.
 */
class ScalableTarget
{
    public function __construct(protected ServerGroup $group = ServerGroup::WEB) {}

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
