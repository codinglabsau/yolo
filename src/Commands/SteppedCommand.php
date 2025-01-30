<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Concerns\RunsSteppedCommands;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;

abstract class SteppedCommand extends Command
{
    use RunsSteppedCommands;

    public function handle(): void
    {
//        if (! $this->shouldBeRunning($this)) {
//            dd('gtfo');
//        }
//
//        ray($this instanceof RunsOnLocal);
//
//        dd('no');

        $environment = $this->argument('environment');

        intro(sprintf("Executing %s steps in %s", $this->getName(), $environment));

        $totalTime = $this->handleSteps($environment);

        if (! $this->option('no-progress')) {
            info(sprintf('Completed successfully in %ss.', $totalTime));
        }
    }
}
