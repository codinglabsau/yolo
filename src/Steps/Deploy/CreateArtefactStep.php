<?php

namespace Codinglabs\Yolo\Steps\Deploy;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Contracts\Step;
use Symfony\Component\Process\Process;

class CreateArtefactStep implements Step
{
    public function __invoke(): void
    {
        (Process::fromShellCommandline(
            command: sprintf('tar czf ../%s * .??*', Helpers::artefactName()),
            cwd: Paths::build(),
            env: [],
            timeout: null
        ))->mustRun();
    }
}
