<?php

namespace Codinglabs\Yolo\Resources\ElbV2;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\ElbV2;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Ec2\Vpc;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\Ecs\MeilisearchService;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * The shared Meilisearch target group. Every declaring app's `search.{apex}`
 * listener rule forwards here — one target group, one task, many hostnames.
 * Health checks Meilisearch's unauthenticated GET /health. Config is frozen at
 * create (no SynchronisesConfiguration) like the rest of the env-shared
 * Meilisearch stack, so apps pinned to different YOLO versions can never
 * thrash it.
 */
class MeilisearchTargetGroup implements Resource
{
    use ResolvesTags;

    public function name(): string
    {
        return $this->keyedName('meilisearch');
    }

    public function scope(): Scope
    {
        return Scope::Env;
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
        $arn = Aws::elasticLoadBalancingV2()->createTargetGroup([
            'Name' => $this->name(),
            'Protocol' => 'HTTP',
            'Port' => MeilisearchService::PORT,
            'TargetType' => 'ip',
            'VpcId' => (new Vpc())->arn(),
            'HealthCheckProtocol' => 'HTTP',
            'HealthCheckPath' => '/health',
            'HealthCheckIntervalSeconds' => 10,
            'HealthCheckTimeoutSeconds' => 8,
            'HealthyThresholdCount' => 2,
            'UnhealthyThresholdCount' => 5,
            'Matcher' => ['HttpCode' => '200'],
            ...Aws::tags($this->tags()),
        ])['TargetGroups'][0]['TargetGroupArn'];

        // Search requests are sub-second — don't hold deploys draining for the
        // AWS default 300s.
        Aws::elasticLoadBalancingV2()->modifyTargetGroupAttributes([
            'TargetGroupArn' => $arn,
            'Attributes' => [
                ['Key' => 'deregistration_delay.timeout_seconds', 'Value' => '10'],
            ],
        ]);
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseElbV2Tags($this->arn(), $this->tags(), $apply);
    }
}
