<?php

namespace Codinglabs\Yolo\Steps\Start\All;

use Codinglabs\Yolo\Contracts\RunsOnAws;
use Codinglabs\Yolo\Contracts\HasSubSteps;

class RestartServicesStep implements HasSubSteps, RunsOnAws
{
    public function __invoke(): array
    {
        return [
            'supervisorctl reread',
            'supervisorctl update',
            'supervisorctl start all',
            'systemctl reload php8.3-fpm',
            'systemctl reload nginx',
        ];
    }
}
