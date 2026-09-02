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
 * Seed-only: the file is the operator's — every later edit arrives via
 * `environment:manifest:push`, so sync creating it once keeps a single writer on each side.
 */
class SeedEnvManifestStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        // remoteExists() also reads false when the bucket itself doesn't exist yet
        // (greenfield plan pass) — the seed reports pending and the bucket step creates it on apply.
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
