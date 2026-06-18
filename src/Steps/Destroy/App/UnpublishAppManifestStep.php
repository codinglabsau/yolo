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
use Codinglabs\Yolo\Steps\Sync\App\PublishAppManifestStep;

/**
 * Removes this app's claim file (`apps/{app}.yml`) from the env config bucket —
 * the reverse of {@see PublishAppManifestStep}.
 * The environment tier reads the union of published claims to decide which
 * env-shared services are still consumed, so unpublishing lets a torn-down app
 * stop holding an env service alive. The env config bucket itself is env-scoped
 * and survives.
 */
class UnpublishAppManifestStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        $bucket = Paths::s3EnvConfigBucket();
        $key = Paths::s3AppManifestKey();

        try {
            Aws::s3()->headObject(['Bucket' => $bucket, 'Key' => $key]);
        } catch (S3Exception $e) {
            if (S3::isNotFound($e)) {
                return StepResult::SKIPPED;
            }

            throw $e;
        }

        $this->recordChange(Change::make($key, 'published', null));

        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_DELETE;
        }

        Aws::s3()->deleteObject(['Bucket' => $bucket, 'Key' => $key]);

        return StepResult::DELETED;
    }
}
