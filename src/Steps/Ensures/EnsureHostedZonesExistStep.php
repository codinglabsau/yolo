<?php

namespace Codinglabs\Yolo\Steps\Ensures;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class EnsureHostedZonesExistStep implements Step
{
    /**
     * @throws ResourceDoesNotExistException
     */
    public function __invoke(array $options): StepResult
    {
        if (Manifest::isMultitenanted()) {
            return StepResult::SKIPPED;
        }

        Manifest::get('apex')
            ? AwsResources::hostedZone(Manifest::get('apex'))
            : AwsResources::hostedZone(Manifest::get('domain'));

        return StepResult::SYNCED;
    }
}
