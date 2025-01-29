<?php

namespace Codinglabs\Yolo\Steps\Ensures;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\EnsuresResourcesExist;
use Codinglabs\Yolo\Contracts\ExecutesStandaloneStep;

class EnsureHostedZonesExistStep implements ExecutesStandaloneStep
{
    use EnsuresResourcesExist;

    public function __invoke(array $options): StepResult
    {
        Manifest::get('apex')
            ? $this->ensure(fn () => AwsResources::hostedZone(Manifest::get('apex')))
            : $this->ensure(fn () => AwsResources::hostedZone(Manifest::get('domain')));

        return StepResult::SYNCED;
    }
}
