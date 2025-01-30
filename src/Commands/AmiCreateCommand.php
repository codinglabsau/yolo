<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Symfony\Component\Console\Input\InputArgument;

class AmiCreateCommand extends SteppedCommand
{
    protected array $steps = [
        Steps\Ensures\EnsureLaunchTemplateExistsStep::class,
        Steps\Ami\LaunchAmiInstanceStep::class,
        Steps\Ami\WaitForUserDataToExecuteStep::class,
        Steps\Ensures\EnsurePhpInstalledStep::class,
        Steps\Ensures\EnsureSwooleInstalledStep::class,
        Steps\Ensures\EnsureNodeInstalledStep::class,
        Steps\Ensures\EnsureNginxInstalledStep::class,
        Steps\Ami\StopAmiInstanceStep::class,
        Steps\Ami\CreateAmiStep::class,
        Steps\Ami\TerminateAmiInstanceStep::class,
    ];

    protected function configure(): void
    {
        $this
            ->setName('ami:create')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->setDescription('Prepare a new Amazon Machine Image');
    }
}
