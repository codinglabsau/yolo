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
 * Stamps the running CLI's release as the environment's version-of-record
 * ({@see EnvironmentVersion}) — declared last, so the stamp only ever lands
 * after the rest of the environment tier has synced under this version.
 *
 * The stamp never regresses (an older release re-syncing an environment is
 * fine — it just doesn't get to lower the record) and never advances from a
 * `dev-*` pin (a moving branch isn't a monotonic version). Skipped by the
 * deploy gate and audit: a version bump reading as "drift" would block every
 * deploy until an admin syncs, which is backwards pressure for what is only a
 * bookkeeping write — the skew WARNING on every sync plan is the guard rail,
 * not this stamp.
 *
 * A direct admin `sync --check` (the CI drift form) is NOT skipped, so it
 * goes red after a release bump until an admin applies a sync. Deliberate:
 * that check's whole job is "does this environment match what the current
 * release would provision", and post-bump it doesn't — the pressure lands on
 * the admin who upgraded, never on app deploys.
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

    /**
     * The running CLI's version — a seam, because the real value in a test
     * run is whatever pin the checkout happens to be on.
     */
    protected function cliVersion(): string
    {
        return Helpers::version();
    }
}
