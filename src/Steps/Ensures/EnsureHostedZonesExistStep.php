<?php

namespace Codinglabs\Yolo\Steps\Ensures;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Aws\Route53;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesSoloStep;
use Codinglabs\Yolo\Concerns\EnsuresResourcesExist;

class EnsureHostedZonesExistStep implements ExecutesSoloStep
{
    use EnsuresResourcesExist;

    public function __invoke(array $options): StepResult
    {
        Manifest::get('apex')
            ? $this->ensure(fn () => Route53::hostedZone(Manifest::get('apex')))
            : $this->ensure(fn () => Route53::hostedZone(Manifest::get('domain')));

        return StepResult::SYNCED;
    }
}
