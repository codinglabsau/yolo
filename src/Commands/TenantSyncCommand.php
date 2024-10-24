<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Concerns\RunsSteppedCommands;
use Symfony\Component\Console\Input\InputArgument;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;

class TenantSyncCommand extends Command
{
    use RunsSteppedCommands;

    protected array $steps = [
        Steps\Tenant\SyncHostedZoneStep::class,
        Steps\Tenant\SyncRecordSetStep::class,
        Steps\Tenant\SyncSslCertificateStep::class,
        Steps\Tenant\AttachSslCertificateToLoadBalancerListenerStep::class,
        Steps\Tenant\SyncQueueStep::class,
        Steps\Tenant\SyncQueueAlarmStep::class,
    ];

    protected function configure(): void
    {
        $this
            ->setName('tenant:sync')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('dry-run', null, null, 'Run the command without making changes')
            ->setDescription('Sync configured tenant AWS resources');
    }

    public function handle(): void
    {
        $environment = $this->argument('environment');

        intro(sprintf("Executing tenant:sync steps in %s", $environment));

        $totalTime = $this->handleSteps($environment);

        info(sprintf('Completed successfully in %ss.', $totalTime));
    }
}
