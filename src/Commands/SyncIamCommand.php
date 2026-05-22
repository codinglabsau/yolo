<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;

class SyncIamCommand extends SyncSteppedCommand
{
    protected array $steps = [
        Steps\Iam\SyncMediaConvertRoleStep::class,
        Steps\Iam\AttachMediaConvertRolePoliciesStep::class,
        Steps\Iam\SyncEcsTaskPolicyStep::class,
        Steps\Iam\SyncEcsTaskRoleStep::class,
        Steps\Iam\AttachEcsTaskRolePoliciesStep::class,
        Steps\Iam\SyncEcsExecutionRoleStep::class,
        Steps\Iam\AttachEcsExecutionRolePoliciesStep::class,
    ];

    protected function configure(): void
    {
        $this->addSyncOptions()
            ->setName('sync:iam')
            ->setDescription('Sync the IAM permissions for the given environment');
    }
}
