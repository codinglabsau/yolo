<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\WebBurstPolicy;

/**
 * The step policy would cascade with the scalable target, but its alarm is
 * standalone. Runs before the scalable target is deregistered.
 */
class DeregisterWebBurstStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        $dryRun = (bool) Arr::get($options, 'dry-run');

        $changes = (new WebBurstPolicy())->teardown(apply: ! $dryRun);

        $this->recordChanges($changes);

        if ($changes === []) {
            return StepResult::SKIPPED;
        }

        return $dryRun ? StepResult::WOULD_DELETE : StepResult::DELETED;
    }
}
