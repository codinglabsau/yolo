<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Symfony\Component\Console\Input\InputArgument;

class ImageCreateCommand extends SteppedCommand
{
    protected array $steps = [
        Steps\Ensures\EnsureKeyPairExistsStep::class,
        Steps\Ensures\EnsureLaunchTemplateExistsStep::class,
        Steps\Image\LaunchAmiInstanceStep::class,
        Steps\Image\WaitForUserDataToExecuteStep::class,
        Steps\Ensures\EnsurePhpInstalledStep::class,
        Steps\Ensures\EnsureSwooleInstalledStep::class,
        Steps\Ensures\EnsureNodeInstalledStep::class,
        Steps\Ensures\EnsureNginxInstalledStep::class,
        Steps\Image\StopAmiInstanceStep::class,
        Steps\Image\CreateAmiStep::class,
        Steps\Image\TerminateAmiInstanceStep::class,
    ];

    protected function configure(): void
    {
        $this
            ->setName('image:create')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('no-progress', null, null, 'Hide the progress output')
            ->setDescription('Prepare a new Amazon Machine Image');
    }
}
