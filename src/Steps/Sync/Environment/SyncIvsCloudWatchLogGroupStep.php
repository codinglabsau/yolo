<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\EnvManifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\CloudWatchLogs\IvsLogGroup;

/**
 * Provisions the env-shared IVS event log group when the environment manifest
 * declares the ivs service (`services.ivs`). One pipeline per environment —
 * the `aws.ivs` event stream is account-wide, so this was never a per-app
 * resource. Apps opt into *consuming* IVS via their own `services: [ivs]`,
 * which grants their task role IVS access.
 */
class SyncIvsCloudWatchLogGroupStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        if (! EnvManifest::has('services.ivs')) {
            return StepResult::SKIPPED;
        }

        return $this->syncResource(new IvsLogGroup(), $options);
    }
}
