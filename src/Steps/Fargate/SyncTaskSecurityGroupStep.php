<?php

namespace Codinglabs\Yolo\Steps\Fargate;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\SecurityGroupRule;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Fargate\EcsTaskSecurityGroup;

class SyncTaskSecurityGroupStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        $securityGroup = new EcsTaskSecurityGroup();

        if ($securityGroup->exists() && Manifest::has('aws.ecs.security-group')) {
            return StepResult::CUSTOM_MANAGED;
        }

        $result = $this->syncResource($securityGroup, $options);

        if ($securityGroup->exists()) {
            $this->ensureLoadBalancerIngressRule($securityGroup->arn(), Arr::get($options, 'dry-run'));
        }

        return $result;
    }

    protected function ensureLoadBalancerIngressRule(string $groupId, bool $dryRun): void
    {
        $rules = Aws::ec2()->describeSecurityGroupRules([
            'Filters' => [
                ['Name' => 'group-id', 'Values' => [$groupId]],
                ['Name' => 'tag:yolo:rule-type', 'Values' => [SecurityGroupRule::ECS_TASK_LB_INGRESS_RULE->value]],
            ],
        ])['SecurityGroupRules'];

        if (! empty($rules) || $dryRun) {
            return;
        }

        $port = (int) Manifest::get('tasks.web.port', 8000);

        Aws::ec2()->authorizeSecurityGroupIngress([
            'GroupId' => $groupId,
            'IpPermissions' => [
                [
                    'IpProtocol' => 'tcp',
                    'FromPort' => $port,
                    'ToPort' => $port,
                    'UserIdGroupPairs' => [
                        [
                            'GroupId' => AwsResources::loadBalancerSecurityGroup()['GroupId'],
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
