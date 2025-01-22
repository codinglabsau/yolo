<?php

namespace Codinglabs\Yolo\Steps\Ensures;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class EnsureTranscoderExistsStep implements Step
{
    /**
     * @throws ResourceDoesNotExistException
     */
    public function __invoke(): StepResult
    {
        if (Manifest::get('aws.transcoder') === null) {
            return StepResult::SKIPPED;
        }

        AwsResources::elasticTranscoderPipeline();
        AwsResources::elasticTranscoderPreset();

        return StepResult::SUCCESS;
    }
}
