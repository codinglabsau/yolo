<?php

namespace Codinglabs\Yolo\Steps\Ensures;

use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class EnsureTranscoderExistsStep implements Step
{
    /**
     * @throws ResourceDoesNotExistException
     */
    public function __invoke(): void
    {
        AwsResources::elasticTranscoderPipeline();
        AwsResources::elasticTranscoderPreset();
    }
}
