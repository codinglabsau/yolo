<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\EnvManifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\RecordsChanges;

/**
 * Seeds the env manifest into the env config bucket on the environment's first
 * sync — and never touches it again. The file is the operator's: every later
 * edit arrives via `environment:manifest:push`, so sync creating it once (seed-only,
 * the WAF IP-set semantics) is what keeps a single writer on each side.
 */
class SeedEnvManifestStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        // remoteExists() also reads false when the bucket itself doesn't exist
        // yet (a greenfield plan pass) — the seed is correctly reported pending
        // and the bucket step, ordered before this one, creates it on apply.
        if (EnvManifest::remoteExists()) {
            return StepResult::SYNCED;
        }

        $this->recordChange(Change::make(EnvManifest::filename(), 'absent', 'seeded'));

        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_CREATE;
        }

        Aws::s3()->putObject([
            'Bucket' => Paths::s3EnvConfigBucket(),
            'Key' => EnvManifest::filename(),
            'Body' => EnvManifest::seedContents(),
        ]);

        return StepResult::CREATED;
    }
}
