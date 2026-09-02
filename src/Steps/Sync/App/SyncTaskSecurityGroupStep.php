<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\SecurityGroupRule;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Ec2\EcsTaskSecurityGroup;
use Codinglabs\Yolo\Resources\Ec2\LoadBalancerSecurityGroup;

class SyncTaskSecurityGroupStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        $securityGroup = new EcsTaskSecurityGroup();

        $result = $this->syncResource($securityGroup, $options);

        // A web-less app's tasks (standalone queue/scheduler) accept no inbound traffic.
        if (Manifest::hasWeb() && $securityGroup->exists()) {
            $this->ensureLoadBalancerIngressRule($securityGroup->arn(), (bool) Arr::get($options, 'dry-run'));
        }

        return $result;
    }

    protected function ensureLoadBalancerIngressRule(string $groupId, bool $dryRun): void
    {
        $rules = Ec2::securityGroupRules($groupId, SecurityGroupRule::ECS_TASK_LB_INGRESS_RULE->value);

        if ($rules !== []) {
            return;
        }

        $port = 8000;

        // Recorded before the dry-run guard so the plan flags this step pending;
        // otherwise a SG that exists but lacks the rule (a create interrupted
        // mid-flight) records no change, gets pruned and never self-heals.
        $this->recordChange(Change::make("ingress {$port}/tcp from load balancer security group", null, 'authorised'));

        if ($dryRun) {
            return;
        }

        Aws::ec2()->authorizeSecurityGroupIngress([
            'GroupId' => $groupId,
            'IpPermissions' => [
                [
                    'IpProtocol' => 'tcp',
                    'FromPort' => $port,
                    'ToPort' => $port,
                    'UserIdGroupPairs' => [
                        [
                            'GroupId' => (new LoadBalancerSecurityGroup())->arn(),
                            'Description' => 'Container port ingress from the load balancer',
                        ],
                    ],
                ],
            ],
            'TagSpecifications' => [
                [
                    'ResourceType' => 'security-group-rule',
                    'Tags' => [
                        ['Key' => 'yolo:rule-type', 'Value' => SecurityGroupRule::ECS_TASK_LB_INGRESS_RULE->value],
                    ],
                ],
            ],
        ]);
    }
}
