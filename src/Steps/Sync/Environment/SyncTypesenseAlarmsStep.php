<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServiceState;
use Codinglabs\Yolo\Services\Lifecycle;
use Codinglabs\Yolo\Services\Typesense;
use Codinglabs\Yolo\Resources\ElbV2\LoadBalancer;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Ecs\ServicesCluster;
use Codinglabs\Yolo\Resources\CloudWatch\Dashboard;
use Codinglabs\Yolo\Resources\Ecs\TypesenseService;
use Codinglabs\Yolo\Resources\ElbV2\SearchTargetGroup;
use Codinglabs\Yolo\Resources\CloudWatch\TypesenseAlarm;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * The cluster's health alarms on the env SNS topic: the quorum pair on the
 * search target group's healthy-host count (< 3 warns — a node is out; < 2 is
 * quorum lost, the cluster degrades to read-only) and a per-node memory alarm
 * (Typesense holds the whole index in memory, so sustained >80% means the
 * offer needs a resize). The healthy-host alarms need the target group and
 * ALB to exist (their CloudWatch dimensions are ARN suffixes), so on a
 * greenfield plan they report pending and land on the next sync.
 */
class SyncTypesenseAlarmsStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        $state = Lifecycle::state(Service::TYPESENSE);

        if ($state === ServiceState::Retain) {
            return StepResult::SKIPPED;
        }

        $results = [];

        foreach ($this->alarms($state) as $alarm) {
            $results[] = $state === ServiceState::Provision
                ? $this->syncResource($alarm, $options)
                : $this->teardownResource($alarm, $options);
        }

        // A node-count reduction leaves memory alarms for nodes that no
        // longer exist — they go even while the service stays provisioned.
        foreach ($this->surplusMemoryAlarms() as $alarm) {
            $results[] = $this->teardownResource($alarm, $options);
        }

        return $this->aggregate($results);
    }

    /**
     * @return array<int, TypesenseAlarm>
     */
    protected function alarms(ServiceState $state): array
    {
        $alarms = $this->healthyHostAlarms($state);
        foreach (range(0, Typesense::nodes() - 1) as $node) {
            $alarms[] = new TypesenseAlarm(
                suffix: sprintf('node-%d-memory', $node),
                description: sprintf('Typesense node %d sustained memory above 80%% - the index is outgrowing the offer, resize services.typesense.memory', $node),
                namespace: 'ECS/ContainerInsights',
                metricName: 'MemoryUtilized',
                dimensions: [
                    ['Name' => 'ClusterName', 'Value' => (new ServicesCluster())->name()],
                    ['Name' => 'ServiceName', 'Value' => (new TypesenseService($node))->name()],
                ],
                statistic: 'Average',
                comparisonOperator: 'GreaterThanThreshold',
                threshold: Typesense::memory() * 0.8,
                evaluationPeriods: 5,
            );
        }

        return $alarms;
    }

    /**
     * The quorum pair — only when the TG/ALB dimensions resolve (teardown
     * tolerates either being gone already; provision reports them pending via
     * the step result on the next sync).
     *
     * @return array<int, TypesenseAlarm>
     */
    /**
     * Memory alarms for node indexes above the declared count — only ever 3
     * and 4, since the valid counts are 3 and 5. Absent alarms skip for free.
     *
     * @return array<int, TypesenseAlarm>
     */
    protected function surplusMemoryAlarms(): array
    {
        $alarms = [];

        // range() counts DOWN when from > to — at the maximum count there is
        // no surplus, so bail before it manufactures one.
        if (Typesense::nodes() >= max(Typesense::NODE_COUNTS)) {
            return [];
        }

        foreach (range(Typesense::nodes(), max(Typesense::NODE_COUNTS) - 1) as $node) {
            $alarms[] = new TypesenseAlarm(
                suffix: sprintf('node-%d-memory', $node),
                description: 'retired',
                namespace: 'ECS/ContainerInsights',
                metricName: 'MemoryUtilized',
                dimensions: [],
                statistic: 'Average',
                comparisonOperator: 'GreaterThanThreshold',
                threshold: 0,
            );
        }

        return $alarms;
    }

    protected function healthyHostAlarms(ServiceState $state): array
    {
        try {
            $dimensions = [
                ['Name' => 'TargetGroup', 'Value' => Dashboard::targetGroupDimension((new SearchTargetGroup())->arn())],
                ['Name' => 'LoadBalancer', 'Value' => Dashboard::loadBalancerDimension((new LoadBalancer())->arn())],
            ];
        } catch (ResourceDoesNotExistException) {
            if ($state === ServiceState::Provision) {
                $this->recordChange(Change::make('typesense healthy-host alarms', null, 'created (target group pending)'));
            }

            return [];
        }

        return [
            new TypesenseAlarm(
                suffix: 'node-out',
                description: 'A Typesense node is out of rotation - the quorum still holds, investigate before a second loss',
                namespace: 'AWS/ApplicationELB',
                metricName: 'HealthyHostCount',
                dimensions: $dimensions,
                statistic: 'Minimum',
                comparisonOperator: 'LessThanThreshold',
                threshold: Typesense::nodes(),
                evaluationPeriods: 3,
            ),
            new TypesenseAlarm(
                suffix: 'quorum-lost',
                description: sprintf('Typesense can no longer take writes (fewer than %d healthy nodes) - search is read-only until a node returns', Typesense::quorumFloor()),
                namespace: 'AWS/ApplicationELB',
                metricName: 'HealthyHostCount',
                dimensions: $dimensions,
                statistic: 'Minimum',
                comparisonOperator: 'LessThanThreshold',
                threshold: Typesense::quorumFloor(),
                evaluationPeriods: 1,
            ),
        ];
    }

    /**
     * @param  array<int, StepResult>  $results
     */
    protected function aggregate(array $results): StepResult
    {
        foreach ([
            StepResult::WOULD_CREATE, StepResult::CREATED,
            StepResult::WOULD_DELETE, StepResult::DELETED,
            StepResult::WOULD_SYNC,
        ] as $significant) {
            if (in_array($significant, $results, true)) {
                return $significant;
            }
        }

        return in_array(StepResult::SYNCED, $results, true) ? StepResult::SYNCED : StepResult::SKIPPED;
    }
}
