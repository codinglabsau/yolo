<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Symfony\Component\Console\Input\InputArgument;

class SyncIamCommand extends SteppedCommand
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
        $this
            ->setName('sync:iam')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('dry-run', null, null, 'Run the command without making changes')
            ->addOption('no-progress', null, null, 'Hide the progress output')
            ->setDescription('Sync the IAM permissions for the given environment');
    }
}
