<?php

namespace Codinglabs\Yolo\Steps\Ensures;

use Codinglabs\Yolo\AwsLookups;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\TenantStep;
use Codinglabs\Yolo\Concerns\EnsuresResourcesExist;

class EnsureMultitenancyHostedZonesExistStep extends TenantStep
{
    use EnsuresResourcesExist;

    public function __invoke(array $options): StepResult
    {
        $this->ensure(fn () => AwsLookups::hostedZone($this->config['apex']));

        return StepResult::SYNCED;
    }
}
