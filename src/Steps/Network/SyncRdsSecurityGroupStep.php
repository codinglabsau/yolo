<?php

namespace Codinglabs\Yolo\Steps\Network;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\SecurityGroup;
use Codinglabs\Yolo\Enums\SecurityGroupRule;
use Codinglabs\Yolo\Resources\Fargate\EcsTaskSecurityGroup;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Provisions the RDS security group and authorises the Fargate tasks to reach
 * the database on 3306. Runs in sync:compute (after SyncTaskSecurityGroupStep)
 * rather than sync:network, because the ingress source is the ECS task SG, which
 * sync:compute creates — the RDS subnet group stays in sync:network.
 *
 * The ingress rule is managed purely additively: we ensure a tagged
 * "3306 from the task SG" rule exists and never revoke anything. Any rule added
 * out of band (e.g. a legacy EC2 SG, a bastion, a hand-granted CIDR) is left
 * untouched, so this can't sever existing database access.
 */
class SyncRdsSecurityGroupStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        try {
            $securityGroup = AwsResources::rdsSecurityGroup();

            if (Manifest::has('aws.rds.security-group')) {
                return StepResult::CUSTOM_MANAGED;
            }

            $this->ensureTaskIngressRule($securityGroup['GroupId'], (bool) Arr::get($options, 'dry-run'));

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (! Arr::get($options, 'dry-run')) {
                $name = Helpers::keyedResourceName(SecurityGroup::RDS_SECURITY_GROUP, exclusive: false);

                Aws::ec2()->createSecurityGroup([
                    'Description' => 'Enable Fargate tasks to connect to RDS',
                    'GroupName' => $name,
                    'VpcId' => AwsResources::vpc()['VpcId'],
                    'TagSpecifications' => [
                        [
                            'ResourceType' => 'security-group',
                            ...Aws::tags([
                                'Name' => $name,
                            ]),
                        ],
                    ],
                ]);

                $this->ensureTaskIngressRule(AwsResources::rdsSecurityGroup()['GroupId'], false);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }

    /**
     * Additively ensure a tagged 3306-from-task-SG ingress rule. Idempotent (keyed
     * off the yolo:rule-type tag), never revokes, and a no-op under --dry-run.
     */
    protected function ensureTaskIngressRule(string $groupId, bool $dryRun): void
    {
        $rules = Ec2::securityGroupRules($groupId, SecurityGroupRule::RDS_TASK_INGRESS_RULE->value);

        if (! empty($rules) || $dryRun) {
            return;
        }

        Aws::ec2()->authorizeSecurityGroupIngress([
            'GroupId' => $groupId,
            'IpPermissions' => [
                [
                    'IpProtocol' => 'tcp',
                    'FromPort' => 3306,
                    'ToPort' => 3306,
                    'UserIdGroupPairs' => [
                        [
                            // Name lookup throws ResourceDoesNotExistException if the
                            // task SG is missing — sync:compute provisions it first.
                            'GroupId' => (new EcsTaskSecurityGroup())->arn(),
                            'Description' => 'Enable Fargate tasks to connect to RDS',
                        ],
                    ],
                ],
            ],
            'TagSpecifications' => [
                [
                    'ResourceType' => 'security-group-rule',
                    'Tags' => [
                        ['Key' => 'yolo:rule-type', 'Value' => SecurityGroupRule::RDS_TASK_INGRESS_RULE->value],
                    ],
                ],
            ],
        ]);
    }
}
