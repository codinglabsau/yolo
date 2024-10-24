<?php

namespace Codinglabs\Yolo\Steps\Ensures;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;

class EnsureS3BucketExistsStep implements Step
{
    public function __invoke(): StepResult
    {
        AwsResources::bucket(Manifest::get('aws.bucket'));

        return StepResult::SYNCED;
    }
}
