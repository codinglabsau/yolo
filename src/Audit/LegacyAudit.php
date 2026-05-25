<?php

namespace Codinglabs\Yolo\Audit;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Enums\LegacyResourceType;

/**
 * Pure classification + costing for `audit:legacy`. Takes the raw output of the
 * Resource Groups Tagging API (plus EC2 metadata for instance pricing) and turns
 * it into a grouped, costed report. No AWS calls here — the command does the I/O
 * and feeds the data in, so all of this is unit-testable in isolation.
 */
class LegacyAudit
{
    private const NAME_TAG = 'Name';

    /**
     * Instance IDs of every yolo-tagged EC2 instance in the inventory — the
     * command resolves their type/state so we can price the compute.
     *
     * @param  array<int, array{ResourceARN: string, Tags?: array<int, array{Key: string, Value: string}>}>  $taggedResources
     * @return array<int, string>
     */
    public static function ec2InstanceIds(array $taggedResources): array
    {
        return collect($taggedResources)
            ->filter(fn (array $resource) => LegacyResourceType::tryFromArn($resource['ResourceARN']) === LegacyResourceType::Ec2Instance)
            ->map(fn (array $resource) => Arn::parse($resource['ResourceARN'])?->resourceId)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Index a list of EC2 instance descriptions by ID for quick lookup during
     * costing.
     *
     * @param  array<int, array<string, mixed>>  $instances  describeInstances Instances entries
     * @return array<string, array{type: ?string, state: ?string}>
     */
    public static function indexInstances(array $instances): array
    {
        return collect($instances)
            ->mapWithKeys(fn (array $instance) => [
                $instance['InstanceId'] => [
                    'type' => $instance['InstanceType'] ?? null,
                    'state' => $instance['State']['Name'] ?? null,
                ],
            ])
            ->all();
    }

    /**
     * Build the legacy-resource report: the classified resources, per-type group
     * totals, the summed monthly estimate and a count of resources we couldn't
     * price.
     *
     * @param  array<int, array{ResourceARN: string, Tags?: array<int, array{Key: string, Value: string}>}>  $taggedResources
     * @param  array<int, string>  $excludedNames  current-deploy names for shared (ELBv2) types
     * @param  array<int, array<string, mixed>>  $instances  describeInstances Instances entries
     * @return array{resources: array<int, array<string, mixed>>, groups: array<int, array<string, mixed>>, totalMonthlyCost: float, unpricedCount: int}
     */
    public static function report(array $taggedResources, array $excludedNames, array $instances, ?string $region): array
    {
        $instanceIndex = static::indexInstances($instances);

        $resources = collect($taggedResources)
            ->map(function (array $resource) use ($excludedNames, $instanceIndex, $region) {
                $arn = $resource['ResourceARN'];
                $type = LegacyResourceType::tryFromArn($arn);

                if ($type === null) {
                    return null;
                }

                $name = Aws::flattenTags($resource['Tags'] ?? [])[self::NAME_TAG] ?? null;

                // A shared ELBv2 resource owned by the current deploy isn't legacy.
                if ($type->isShared() && $name !== null && in_array($name, $excludedNames, true)) {
                    return null;
                }

                [$detail, $cost] = static::detailAndCost($type, $arn, $instanceIndex, $region);

                return [
                    'type' => $type->value,
                    'label' => $type->label(),
                    'name' => $name ?? Arn::parse($arn)?->resourceId ?? $arn,
                    'arn' => $arn,
                    'detail' => $detail,
                    'monthlyCost' => $cost,
                ];
            })
            ->filter()
            ->values();

        $groups = $resources
            ->groupBy('label')
            ->map(fn ($items, string $label) => [
                'label' => $label,
                'count' => $items->count(),
                'monthlyCost' => round($items->sum(fn (array $item) => $item['monthlyCost'] ?? 0.0), 2),
            ])
            ->values()
            ->all();

        return [
            'resources' => $resources->all(),
            'groups' => $groups,
            'totalMonthlyCost' => round($resources->sum(fn (array $item) => $item['monthlyCost'] ?? 0.0), 2),
            'unpricedCount' => $resources->filter(fn (array $item) => $item['monthlyCost'] === null)->count(),
        ];
    }

    /**
     * The display detail and estimated monthly cost for a single resource.
     * Compute cost rides on the instances (priced from their type/state); the
     * ASG, launch template and friends are free-standing, so they cost $0. An
     * orphaned load balancer still bills its hourly baseline.
     *
     * @param  array<string, array{type: ?string, state: ?string}>  $instanceIndex
     * @return array{0: string, 1: ?float}
     */
    protected static function detailAndCost(LegacyResourceType $type, string $arn, array $instanceIndex, ?string $region): array
    {
        if ($type === LegacyResourceType::Ec2Instance) {
            $meta = $instanceIndex[Arn::parse($arn)?->resourceId] ?? ['type' => null, 'state' => null];
            $detail = collect([$meta['type'], $meta['state']])->filter()->implode(' · ');

            $cost = match (true) {
                $meta['state'] !== 'running' => 0.0,
                $meta['type'] === null => null,
                default => Pricing::ec2Monthly($meta['type'], $region),
            };

            return [$detail, $cost];
        }

        if ($type === LegacyResourceType::LoadBalancer) {
            return ['baseline', Pricing::loadBalancerMonthly($region)];
        }

        return ['', 0.0];
    }
}
