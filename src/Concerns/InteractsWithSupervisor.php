<?php

namespace Codinglabs\Yolo\Concerns;

use Symfony\Component\Process\Process;

trait InteractsWithSupervisor
{
    public function stopSupervisorWorkers(): void
    {
        Process::fromShellCommandline(
            command: 'supervisorctl stop all'
        )->mustRun();
    }
}
