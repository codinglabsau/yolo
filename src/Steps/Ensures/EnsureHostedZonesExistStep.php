<?php

namespace Codinglabs\Yolo\Steps\Ensures;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesDomainStep;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class EnsureHostedZonesExistStep implements ExecutesDomainStep
{
    /**
     * @throws ResourceDoesNotExistException
     */
    public function __invoke(array $options): StepResult
    {
        Manifest::get('apex')
            ? AwsResources::hostedZone(Manifest::get('apex'))
            : AwsResources::hostedZone(Manifest::get('domain'));

        return StepResult::SYNCED;
    }
}
