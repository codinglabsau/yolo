<?php

namespace Codinglabs\Yolo\Steps\Start\All;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\RunsOnAws;
use Codinglabs\Yolo\Contracts\HasSubSteps;

class SetOwnershipAndPermissionsStep implements HasSubSteps, RunsOnAws
{
    public function __invoke(): array
    {
        $name = Manifest::name();

        return [
            sprintf('mkdir -p %s', Paths::yoloDir()),
            sprintf('mkdir -p %s', Paths::logDir()),
            'chown -R ubuntu:ubuntu /home/ubuntu',
            'chown -R ubuntu:ubuntu /var/log/yolo',
            'chown -R ubuntu:ubuntu /var/www',
            "chmod -R 757 /var/www/$name/storage",
        ];
    }
}
