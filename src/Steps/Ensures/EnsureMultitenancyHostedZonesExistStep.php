<?php

namespace Codinglabs\Yolo\Steps\Ensures;

use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\TenantStep;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class EnsureMultitenancyHostedZonesExistStep extends TenantStep
{
    /**
     * @throws ResourceDoesNotExistException
     */
    public function __invoke(array $options): StepResult
    {
        AwsResources::hostedZone($this->config['apex']);

        return StepResult::SYNCED;
    }
}
