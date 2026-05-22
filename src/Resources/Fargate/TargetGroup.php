<?php

namespace Codinglabs\Yolo\Resources\Fargate;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Aws\ElbV2;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class TargetGroup implements Resource, SynchronisesConfiguration
{
    public function name(): string
    {
        return Helpers::keyedResourceName(exclusive: true);
    }

    public function tags(): array
    {
        return ['Name' => $this->name()];
    }

    public function exists(): bool
    {
        try {
            ElbV2::targetGroup($this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return ElbV2::targetGroup($this->name())['TargetGroupArn'];
    }

    public function create(): void
    {
        Aws::elasticLoadBalancingV2()->createTargetGroup([
            'Name' => $this->name(),
            'Protocol' => 'HTTP',
            'Port' => (int) Manifest::get('tasks.web.port', 8000),
            'TargetType' => 'ip',
            // VPC lookup is still on the legacy AwsResources facade — covered by LPX-612.
            'VpcId' => AwsResources::vpc()['VpcId'],
            'HealthCheckProtocol' => 'HTTP',
            ...static::reconcilableHealthCheck(),
            ...Aws::tags($this->tags()),
        ]);
    }

    public function synchroniseTags(): void
    {
        Aws::synchroniseElbV2Tags($this->arn(), $this->tags());
    }

    /**
     * Push managed health-check config onto an existing target group. Create
     * sets these once; without this, a changed default or manifest override
     * (`tasks.web.health-check.*`) would never reach an already-deployed app —
     * tag sync alone doesn't cover them. Diffs first so a clean sync makes no
     * needless ModifyTargetGroup call. (The service grace period is a separate,
     * service-level setting reconciled by EcsService.)
     */
    public function synchroniseConfiguration(): void
    {
        $live = ElbV2::targetGroup($this->name());
        $desired = static::reconcilableHealthCheck();

        $current = [
            'HealthCheckPath' => $live['HealthCheckPath'] ?? null,
            'HealthCheckIntervalSeconds' => $live['HealthCheckIntervalSeconds'] ?? null,
            'HealthCheckTimeoutSeconds' => $live['HealthCheckTimeoutSeconds'] ?? null,
            'HealthyThresholdCount' => $live['HealthyThresholdCount'] ?? null,
            'UnhealthyThresholdCount' => $live['UnhealthyThresholdCount'] ?? null,
            'Matcher' => ['HttpCode' => $live['Matcher']['HttpCode'] ?? null],
        ];

        if ($current == $desired) {
            return;
        }

        Aws::elasticLoadBalancingV2()->modifyTargetGroup([
            'TargetGroupArn' => $live['TargetGroupArn'],
            ...$desired,
        ]);
    }

    /**
     * The health-check fields create sets and sync keeps current — one source
     * of truth so the two paths can't drift apart. Timeout must stay below the
     * interval (an AWS constraint on ModifyTargetGroup).
     *
     * @return array<string, mixed>
     */
    public static function reconcilableHealthCheck(): array
    {
        return [
            'HealthCheckPath' => Manifest::get('tasks.web.health-check.path', '/health'),
            'HealthCheckIntervalSeconds' => (int) Manifest::get('tasks.web.health-check.interval', 10),
            'HealthCheckTimeoutSeconds' => (int) Manifest::get('tasks.web.health-check.timeout', 5),
            'HealthyThresholdCount' => (int) Manifest::get('tasks.web.health-check.healthy-threshold', 2),
            'UnhealthyThresholdCount' => (int) Manifest::get('tasks.web.health-check.unhealthy-threshold', 3),
            'Matcher' => ['HttpCode' => '200'],
        ];
    }
}
