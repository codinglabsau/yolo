<?php

namespace Codinglabs\Yolo\Steps\Ensures;

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;

class EnsureS3ArtefactBucketExistsStep implements Step
{
    public function __invoke(): StepResult
    {
        AwsResources::bucket(Helpers::keyedResourceName('artefacts'));

        return StepResult::SYNCED;
    }
}
