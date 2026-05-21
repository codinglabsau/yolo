<?php

namespace Codinglabs\Yolo\Resources\Fargate;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsLookups;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class TargetGroup implements Resource
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
            AwsLookups::targetGroup();

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return AwsLookups::targetGroup()['TargetGroupArn'];
    }

    public function create(): void
    {
        Aws::elasticLoadBalancingV2()->createTargetGroup([
            'Name' => $this->name(),
            'Protocol' => 'HTTP',
            'Port' => (int) Manifest::get('tasks.web.port', 8000),
            'TargetType' => 'ip',
            'VpcId' => AwsLookups::vpc()['VpcId'],
            'HealthCheckProtocol' => 'HTTP',
            'HealthCheckPath' => Manifest::get('tasks.web.health-check.path', '/health'),
            'HealthCheckIntervalSeconds' => (int) Manifest::get('tasks.web.health-check.interval', 30),
            'HealthCheckTimeoutSeconds' => (int) Manifest::get('tasks.web.health-check.timeout', 5),
            'HealthyThresholdCount' => (int) Manifest::get('tasks.web.health-check.healthy-threshold', 2),
            'UnhealthyThresholdCount' => (int) Manifest::get('tasks.web.health-check.unhealthy-threshold', 3),
            'Matcher' => ['HttpCode' => '200'],
            ...Aws::tags($this->tags()),
        ]);
    }

    public function synchroniseTags(): void
    {
        Aws::synchroniseElbV2Tags($this->arn(), $this->tags());
    }
}
