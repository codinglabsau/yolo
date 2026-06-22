<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\RecordsWarnings;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;

/**
 * Base for the env-shared bucket teardowns. These hold data — the env manifest +
 * the env-shared .env (env config bucket), the ALB access logs (env logs bucket) —
 * so they're emptied and deleted only when the operator opts in with --delete-data.
 * Without the flag the step leaves the bucket (and its data) standing and warns,
 * so a default destroy:environment never silently destroys data.
 */
abstract class RemoveDataBucketStep extends TeardownStep
{
    use RecordsWarnings;

    #[\Override]
    public function __invoke(array $options): StepResult
    {
        $bucket = $this->resource();

        if (! Arr::get($options, 'delete-data')) {
            if ($bucket->exists()) {
                $this->recordWarning(sprintf(
                    '%s left in place — re-run with --delete-data to empty and delete it.',
                    $bucket->name(),
                ));
            }

            return StepResult::SKIPPED;
        }

        return $this->teardownResource($bucket, $options);
    }
}
