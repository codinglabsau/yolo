<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\WebBurstPolicy;

/**
 * Tears down the web burst scale-out path — the high-res worker-saturation alarm
 * and its step-scaling policy. The step policy cascades when the scalable target
 * is deregistered, but the alarm is standalone, so {@see WebBurstPolicy::teardown()}
 * removes both. Run before the scalable target is deregistered.
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
