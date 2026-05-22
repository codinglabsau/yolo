<?php

namespace Codinglabs\Yolo\Steps\CloudFront;

use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\CloudFront\AssetDistribution;

class SyncAssetDistributionStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new AssetDistribution(), $options);
    }
}
