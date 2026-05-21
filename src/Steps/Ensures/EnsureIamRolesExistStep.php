<?php

namespace Codinglabs\Yolo\Steps\Ensures;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsLookups;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\EnsuresResourcesExist;

class EnsureIamRolesExistStep implements Step
{
    use EnsuresResourcesExist;

    public function __invoke(): StepResult
    {
        $this->ensure(fn () => AwsLookups::ec2Role());

        if (Manifest::get('aws.mediaconvert')) {
            $this->ensure(fn () => AwsLookups::mediaConvertRole());
        }

        return StepResult::SUCCESS;
    }
}
