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

        // Only a web task sits behind the ALB — a web-less app's tasks (standalone
        // queue/scheduler) accept no inbound traffic, so no ingress rule at all.
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

        // Record the missing rule before the dry-run guard (mirroring AuthorisesTaskIngress)
        // so the plan pass flags this step pending. Without it a SG that exists but lacks
        // the rule — e.g. a create interrupted mid-flight — records no change, gets pruned
        // before apply, and can never be self-healed by a later sync.
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
