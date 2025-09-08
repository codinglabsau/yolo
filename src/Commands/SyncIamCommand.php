<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Symfony\Component\Console\Input\InputArgument;

class SyncIamCommand extends SteppedCommand
{
    protected array $steps = [
        Steps\Iam\SyncRoleStep::class,
        Steps\Iam\SyncRolePolicyStep::class,
        Steps\Iam\AttachRolePoliciesStep::class,
        Steps\Iam\SyncInstanceProfileStep::class,
        Steps\Iam\AttachRoleToInstanceProfileStep::class,
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
