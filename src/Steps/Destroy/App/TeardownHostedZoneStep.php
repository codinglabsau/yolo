<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Route53\HostedZone;

/**
 * Tears down this app's Route 53 hosted zone.
 */
class TeardownHostedZoneStep extends TeardownStep
{
    protected function resource(): HostedZone
    {
        return new HostedZone(Manifest::apex());
    }
}
