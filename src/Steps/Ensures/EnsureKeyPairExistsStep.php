<?php

namespace Codinglabs\Yolo\Steps\Ensures;

use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\EnsuresResourcesExist;

class EnsureKeyPairExistsStep implements Step
{
    use EnsuresResourcesExist;

    public function __invoke(): StepResult
    {
        $this->ensure(fn () => AwsResources::keyPair());

        return StepResult::SYNCED;
    }
}
