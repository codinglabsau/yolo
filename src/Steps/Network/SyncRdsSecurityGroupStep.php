<?php

namespace Codinglabs\Yolo\Steps\Network;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Network\RdsSecurityGroup;
use Codinglabs\Yolo\Resources\Fargate\EcsTaskSecurityGroup;

/**
 * Provisions the RDS security group and authorises the Fargate tasks to reach
 * the database on 3306. Runs in sync:compute (after SyncTaskSecurityGroupStep)
 * rather than sync:network, because the ingress source is the ECS task SG, which
 * sync:compute creates — the RDS subnet group stays in sync:network.
 *
 * The ingress rule is managed purely additively: we ensure a "3306 from the task
 * SG" rule exists and never revoke anything. Any rule added out of band (e.g. a
 * legacy EC2 SG, a bastion, a hand-granted CIDR) is left untouched, so this can't
 * sever existing database access.
 */
class SyncRdsSecurityGroupStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        $securityGroup = new RdsSecurityGroup();

        if (Manifest::has('aws.rds.security-group') && $securityGroup->exists()) {
            return StepResult::CUSTOM_MANAGED;
        }

        $result = $this->syncResource($securityGroup, $options);

        if ($securityGroup->exists()) {
            $this->ensureTaskIngressRule($securityGroup->arn(), (bool) Arr::get($options, 'dry-run'));
        }

        return $result;
    }

    /**
     * Additively ensure a 3306-from-task-SG ingress rule exists, identified by its
     * content (AWS rejects duplicate permissions anyway, so no marker tag is
     * needed). Never revokes, and a no-op under --dry-run.
     */
    protected function ensureTaskIngressRule(string $groupId, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }

        // Name lookup throws ResourceDoesNotExistException if the task SG is
        // missing — sync:compute provisions it before this step runs.
        $taskSecurityGroupId = (new EcsTaskSecurityGroup())->arn();

        $alreadyAuthorised = collect(Ec2::securityGroupRules($groupId))->contains(
            fn (array $rule) => ! ($rule['IsEgress'] ?? false)
                && ($rule['IpProtocol'] ?? null) === 'tcp'
                && ($rule['FromPort'] ?? null) === 3306
                && ($rule['ReferencedGroupInfo']['GroupId'] ?? null) === $taskSecurityGroupId
        );

        if ($alreadyAuthorised) {
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
                            'GroupId' => $taskSecurityGroupId,
                            'Description' => 'Enable Fargate tasks to connect to RDS',
                        ],
                    ],
                ],
            ],
        ]);
    }
}
