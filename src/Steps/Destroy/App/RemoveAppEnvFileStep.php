<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Aws\S3;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Aws\S3\Exception\S3Exception;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\RecordsChanges;

/**
 * Deletes this app's per-app env file (env/.env.{app}) from the env config
 * bucket — the build's per-app environment channel, which also carries any
 * Typesense keys sync:app minted. One object per app, so removing it touches
 * only this app and never the env-shared .env beside it; the env config bucket
 * itself is env-shared and left standing.
 */
class RemoveAppEnvFileStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        if (! $this->exists()) {
            return StepResult::SKIPPED;
        }

        $this->recordChange(Change::make(Paths::s3EnvAppEnvKey(), 'provisioned', null));

        if ((bool) Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_DELETE;
        }

        Aws::s3()->deleteObject([
            'Bucket' => Paths::s3EnvConfigBucket(),
            'Key' => Paths::s3EnvAppEnvKey(),
        ]);

        return StepResult::DELETED;
    }

    protected function exists(): bool
    {
        try {
            Aws::s3()->headObject([
                'Bucket' => Paths::s3EnvConfigBucket(),
                'Key' => Paths::s3EnvAppEnvKey(),
            ]);

            return true;
        } catch (S3Exception $e) {
            if (S3::isNotFound($e)) {
                return false;
            }

            throw $e;
        }
    }
}
