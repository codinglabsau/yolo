<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Ec2\RdsSecurityGroup;
use Codinglabs\Yolo\Resources\Ec2\EcsTaskSecurityGroup;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

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

        $dryRun = (bool) Arr::get($options, 'dry-run');
        $result = $this->syncResource($securityGroup, $options);

        if ($securityGroup->exists() && $this->reconcileTaskIngressRule($securityGroup->arn(), $dryRun) && $dryRun && $result === StepResult::SYNCED) {
            // The group already exists but the ingress rule is missing, so a
            // dry-run has a pending change to report rather than a clean SYNCED.
            $result = StepResult::WOULD_SYNC;
        }

        return $result;
    }

    /**
     * Additively ensure a 3306-from-task-SG ingress rule exists, identified by its
     * content (AWS rejects duplicate permissions anyway, so no marker tag is
     * needed). Never revokes, records the change it makes, and writes nothing under
     * --dry-run. Returns whether the rule is missing (a change is pending/applied).
     */
    protected function reconcileTaskIngressRule(string $groupId, bool $dryRun): bool
    {
        try {
            // Name lookup throws ResourceDoesNotExistException if the task SG is
            // missing — sync:compute provisions it before this step runs, but a
            // dry-run on a fresh environment can reach here before it exists.
            $taskSecurityGroupId = (new EcsTaskSecurityGroup())->arn();
        } catch (ResourceDoesNotExistException) {
            $this->recordChange(Change::make('ingress 3306/tcp from task security group', null, 'authorised (task SG pending)'));

            return true;
        }

        $alreadyAuthorised = collect(Ec2::securityGroupRules($groupId))->contains(
            fn (array $rule) => ! ($rule['IsEgress'] ?? false)
                && ($rule['IpProtocol'] ?? null) === 'tcp'
                && ($rule['FromPort'] ?? null) === 3306
                && ($rule['ReferencedGroupInfo']['GroupId'] ?? null) === $taskSecurityGroupId
        );

        if ($alreadyAuthorised) {
            return false;
        }

        $this->recordChange(Change::make('ingress 3306/tcp from task security group', null, $taskSecurityGroupId));

        if (! $dryRun) {
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

        return true;
    }
}
