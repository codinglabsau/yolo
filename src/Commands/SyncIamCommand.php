<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Symfony\Component\Console\Input\InputArgument;

class SyncIamCommand extends SteppedCommand
{
    protected array $steps = [
        Steps\Iam\SyncEc2PolicyStep::class,
        Steps\Iam\SyncEc2RoleStep::class,
        Steps\Iam\SyncEc2AttachRolePolicyStep::class,
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
