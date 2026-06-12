<?php

namespace Codinglabs\Yolo\Resources\ElbV2;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\ElbV2;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Ec2\Vpc;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Services\Typesense;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * The env-shared target group fronting all three Typesense nodes on the
 * search API port — browser traffic enters here via the `search.{domain}`
 * listener rule; each node's ECS service registers its task IP. Health checks
 * Typesense's own /health, which doubles as the rollout's readiness signal
 * (an unhealthy or catching-up node drops out of rotation while the quorum
 * keeps serving).
 */
class SearchTargetGroup implements Deletable, Resource
{
    use ResolvesTags;

    public function name(): string
    {
        return $this->keyedName('search');
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
            'Port' => Typesense::API_PORT,
            'TargetType' => 'ip',
            'VpcId' => (new Vpc())->arn(),
            'HealthCheckProtocol' => 'HTTP',
            'HealthCheckPath' => '/health',
            'HealthCheckIntervalSeconds' => 15,
            'HealthyThresholdCount' => 2,
            'UnhealthyThresholdCount' => 3,
            ...Aws::tags($this->tags()),
        ])['TargetGroups'][0]['TargetGroupArn'];

        // Search requests are short-lived — a draining node doesn't need the
        // 300s default to finish in-flight queries.
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

    public function delete(): void
    {
        Aws::elasticLoadBalancingV2()->deleteTargetGroup([
            'TargetGroupArn' => $this->arn(),
        ]);
    }
}
