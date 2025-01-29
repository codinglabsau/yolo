<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Contracts\RunsOnAws;
use Symfony\Component\Console\Input\InputArgument;

class StopCommand extends SteppedCommand implements RunsOnAws
{
    protected array $steps = [
        Steps\Stop\StopWorkOnQueueStep::class,
        Steps\Stop\StopWorkOnSchedulerStep::class,
        Steps\Stop\StopWorkOnWebStep::class,
    ];

    protected function configure(): void
    {
        $this
            ->setName('stop')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('no-progress', null, null, 'Hide the progress output')
            ->setDescription('Stop work before deployment');
    }
}
