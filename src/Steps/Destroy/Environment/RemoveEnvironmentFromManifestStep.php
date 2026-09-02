<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Concerns\RecordsWarnings;

/**
 * Runs dead last because the teardown ahead of it still reads the environment's
 * account/region out of the block. If the block can't be edited safely it
 * writes nothing and warns rather than risk corrupting the file.
 */
class RemoveEnvironmentFromManifestStep implements Step
{
    use RecordsChanges;
    use RecordsWarnings;

    public function __invoke(array $options): StepResult
    {
        $environment = Helpers::environment();

        if (! Manifest::environmentExists($environment)) {
            return StepResult::SKIPPED;
        }

        $this->recordChange(Change::make("environment {$environment} in yolo.yml", 'declared', null));

        if ((bool) Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_DELETE;
        }

        if (! Manifest::removeEnvironment($environment)) {
            $this->recordWarning(sprintf(
                "Couldn't safely remove the %s environment from yolo.yml — delete the environments.%s block by hand.",
                $environment,
                $environment,
            ));

            return StepResult::SKIPPED;
        }

        return StepResult::DELETED;
    }
}
