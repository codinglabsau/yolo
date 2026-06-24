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
 * The final act of an environment teardown — destroy:app (the last app retires
 * its environment), destroy:environment, and the destroy orchestrator all end
 * here: drop this environment's entire block from the local yolo.yml, so the
 * manifest stops advertising a deployment target whose resources have just been
 * torn down. It runs dead last because the teardown ahead of it still reads the
 * environment's account/region out of the block. A local-file change, not an AWS
 * one — the reverse of declaring the environment to deploy to it. Surgical and
 * format-preserving (see {@see Manifest::removeEnvironment()}); if the block's
 * layout can't be edited safely it writes nothing and warns the operator to
 * remove it by hand rather than risk corrupting the file. A standalone
 * destroy:environment whose block was already removed simply SKIPs.
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
