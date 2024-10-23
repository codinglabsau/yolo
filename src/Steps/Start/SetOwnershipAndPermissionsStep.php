<?php

namespace Codinglabs\Yolo\Steps\Start;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\RunsOnAws;
use Codinglabs\Yolo\Contracts\HasSubSteps;

class SetOwnershipAndPermissionsStep implements HasSubSteps, RunsOnAws
{
    public function __invoke(): array
    {
        $name = Manifest::name();

        return [
            "chown -R ubuntu:ubuntu /var/www",
            "chmod -R 757 /var/www/$name/storage",
            "chmod -R 757 /var/www/$name/resources/views/components/pages",
        ];
    }
}
