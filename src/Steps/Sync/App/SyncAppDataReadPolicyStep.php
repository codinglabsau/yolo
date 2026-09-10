<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Iam\AppDataReadPolicy;

/**
 * Gated on the app declaring a data bucket: with none the document would have
 * no resource, which IAM rejects. The attach steps carry the same gate.
 */
class SyncAppDataReadPolicyStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        if (! Manifest::has('bucket')) {
            return StepResult::SKIPPED;
        }

        return $this->syncResource(new AppDataReadPolicy(), $options);
    }
}
