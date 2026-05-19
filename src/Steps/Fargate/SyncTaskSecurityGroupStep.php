<?php

namespace Codinglabs\Yolo\Steps\Fargate;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\SecurityGroup;
use Codinglabs\Yolo\Enums\SecurityGroupRule;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncTaskSecurityGroupStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        $containerPort = (int) Manifest::get('tasks.web.port', 8000);

        try {
            $securityGroup = AwsResources::ecsTaskSecurityGroup();

            if (Manifest::has('aws.ecs.security-group')) {
                return StepResult::CUSTOM_MANAGED;
            }

            $rules = Aws::ec2()->describeSecurityGroupRules([
                'Filters' => [
                    ['Name' => 'group-id', 'Values' => [$securityGroup['GroupId']]],
                    ['Name' => 'tag:yolo:rule-type', 'Values' => [SecurityGroupRule::ECS_TASK_LB_INGRESS_RULE->value]],
                ],
            ])['SecurityGroupRules'];

            if (empty($rules)) {
                if (Arr::get($options, 'dry-run')) {
                    return StepResult::OUT_OF_SYNC;
                }

                Aws::ec2()->authorizeSecurityGroupIngress(static::loadBalancerIngressRule($securityGroup, $containerPort));
            }

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (Arr::get($options, 'dry-run')) {
                return StepResult::WOULD_CREATE;
            }

            $name = Helpers::keyedResourceName(SecurityGroup::ECS_TASK_SECURITY_GROUP, exclusive: true);

            $created = Aws::ec2()->createSecurityGroup([
                'Description' => 'Enable load balancer traffic to Fargate task ENI',
                'GroupName' => $name,
                'VpcId' => AwsResources::vpc()['VpcId'],
                'TagSpecifications' => [
                    [
                        'ResourceType' => 'security-group',
                        ...Aws::tags(['Name' => $name]),
                    ],
                ],
            ]);

            Aws::ec2()->authorizeSecurityGroupIngress(
                static::loadBalancerIngressRule(['GroupId' => $created['GroupId']], $containerPort)
            );

            return StepResult::CREATED;
        }
    }

    protected static function loadBalancerIngressRule(array $securityGroup, int $port): array
    {
        return [
            'GroupId' => $securityGroup['GroupId'],
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
        ];
    }
}
