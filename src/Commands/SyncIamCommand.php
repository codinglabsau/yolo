<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Symfony\Component\Console\Input\InputArgument;

class SyncIamCommand extends SteppedCommand
{
    protected array $steps = [
        Steps\Iam\SyncEc2RoleStep::class,
        Steps\Iam\SyncEc2RolePolicyStep::class,
        Steps\Iam\AttachEc2RolePoliciesStep::class,
        Steps\Iam\SyncEc2InstanceProfileStep::class,
        Steps\Iam\AttachEc2RoleToInstanceProfileStep::class,
        Steps\Iam\SyncMediaConvertRoleStep::class,
        Steps\Iam\AttachMediaConvertRolePoliciesStep::class,
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
