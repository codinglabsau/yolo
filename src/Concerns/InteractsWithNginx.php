<?php

namespace Codinglabs\Yolo\Concerns;

use Symfony\Component\Process\Process;

trait InteractsWithNginx
{
    public function stopNginx(): void
    {
        Process::fromShellCommandline(
            command: 'systemctl stop nginx'
        )->mustRun();
    }
}
