<?php

namespace Codinglabs\Yolo\Steps\Start;

use Codinglabs\Yolo\Contracts\RunsOnAws;
use Codinglabs\Yolo\Contracts\HasSubSteps;

class RestartServicesStep implements HasSubSteps, RunsOnAws
{
    public function __invoke(): array
    {
        return [
            'supervisorctl reread',
            'supervisorctl update',
            'supervisorctl start all', // note we already stopped supervisor workers in beforeInstall hook, so we "start" rather than "restart" here.
            'systemctl reload php8.3-fpm',
            'systemctl reload nginx',
        ];
    }
}
