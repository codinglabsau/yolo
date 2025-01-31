<?php

namespace Codinglabs\Yolo\Steps\Image;

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Symfony\Component\Process\Process;
use Codinglabs\Yolo\Concerns\FormatsSshCommands;

class WaitForUserDataToExecuteStep implements Step
{
    use FormatsSshCommands;

    public function __invoke(): StepResult
    {
        while (true) {
            $finished = Process::fromShellCommandline(
                command: static::formatSshCommand(Helpers::app('amiIp')) . ' "test -f /home/ubuntu/finished"',
            )->run();

            if ($finished === 0) {
                break;
            }

            sleep(3);
        }

        return StepResult::SUCCESS;
    }
}
