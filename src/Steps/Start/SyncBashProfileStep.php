<?php

namespace Codinglabs\Yolo\Steps\Start;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\RunsOnAws;

class SyncBashProfileStep implements RunsOnAws
{
    public function __invoke(array $options): StepResult
    {
        file_put_contents(
            "/home/ubuntu/.bash_profile",
            file_get_contents(Paths::stubs('.bash_profile.stub'))
        );

        chown("/home/ubuntu/.bash_profile", "ubuntu");

        return StepResult::SYNCED;
    }
}
