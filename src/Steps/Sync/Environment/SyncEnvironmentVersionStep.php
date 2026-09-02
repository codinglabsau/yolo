<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\EnvironmentVersion;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Contracts\SkippedByDeployCheck;

/**
 * Declared last, so the stamp only lands after the rest of the environment tier
 * has synced under this version. Never regresses (an older release re-syncing
 * just doesn't get to lower the record) and never advances from a `dev-*` pin
 * (a moving branch isn't a monotonic version).
 *
 * Skipped by the deploy gate and audit: a version bump reading as drift would
 * block every deploy until an admin syncs — backwards pressure for a
 * bookkeeping write; the skew WARNING on every sync plan is the guard rail. A
 * direct admin `sync --check` is NOT skipped and goes red after a release bump
 * until an admin applies a sync — deliberate: the pressure lands on the admin
 * who upgraded, never on app deploys.
 */
class SyncEnvironmentVersionStep implements SkippedByDeployCheck, Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        $cli = $this->cliVersion();

        if (! Helpers::isReleaseVersion($cli)) {
            return StepResult::SKIPPED;
        }

        $stamped = EnvironmentVersion::stamped();

        if ($stamped !== null && version_compare(ltrim($cli, 'v'), ltrim($stamped, 'v'), '<=')) {
            return StepResult::SYNCED;
        }

        $this->recordChange(Change::make(EnvironmentVersion::MARKER_KEY, $stamped, $cli));

        if ((bool) Arr::get($options, 'dry-run')) {
            return $stamped === null ? StepResult::WOULD_CREATE : StepResult::WOULD_SYNC;
        }

        EnvironmentVersion::stamp($cli);

        return $stamped === null ? StepResult::CREATED : StepResult::SYNCED;
    }

    /** A seam: in a test run the real value is whatever pin the checkout is on. */
    protected function cliVersion(): string
    {
        return Helpers::version();
    }
}
