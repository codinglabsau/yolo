<?php

namespace Codinglabs\Yolo\Steps\Ensures;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\EnsuresResourcesExist;

class EnsureTranscoderExistsStep implements Step
{
    use EnsuresResourcesExist;

    public function __invoke(): StepResult
    {
        if (Manifest::get('aws.transcoder') === null) {
            return StepResult::SKIPPED;
        }

        $this->ensure(fn () => AwsResources::elasticTranscoderPipeline());
        $this->ensure(fn () => AwsResources::elasticTranscoderPreset());

        return StepResult::SUCCESS;
    }
}
