<?php

namespace Codinglabs\Yolo\Steps\Fargate;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncTargetGroupStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        try {
            AwsResources::targetGroup();

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (Arr::get($options, 'dry-run')) {
                return StepResult::WOULD_CREATE;
            }

            $name = Helpers::keyedResourceName(exclusive: true);
            $port = (int) Manifest::get('tasks.web.port', 8000);

            Aws::elasticLoadBalancingV2()->createTargetGroup([
                'Name' => $name,
                'Protocol' => 'HTTP',
                'Port' => $port,
                'TargetType' => 'ip',
                'VpcId' => AwsResources::vpc()['VpcId'],
                'HealthCheckProtocol' => 'HTTP',
                'HealthCheckPath' => Manifest::get('tasks.web.health-check.path', '/health'),
                'HealthCheckIntervalSeconds' => (int) Manifest::get('tasks.web.health-check.interval', 30),
                'HealthCheckTimeoutSeconds' => (int) Manifest::get('tasks.web.health-check.timeout', 5),
                'HealthyThresholdCount' => (int) Manifest::get('tasks.web.health-check.healthy-threshold', 2),
                'UnhealthyThresholdCount' => (int) Manifest::get('tasks.web.health-check.unhealthy-threshold', 3),
                'Matcher' => ['HttpCode' => '200'],
                ...Aws::tags(['Name' => $name]),
            ]);

            return StepResult::CREATED;
        }
    }
}
