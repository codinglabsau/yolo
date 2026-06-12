<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Sync\App;

use RuntimeException;
use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Aws\S3;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Symfony\Component\Yaml\Yaml;
use Aws\S3\Exception\S3Exception;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\RecordsChanges;

/**
 * Publishes this app's claim file (`apps/{app}.yml` — the app name + the
 * services it consumes) into the env config bucket, on every sync:app and
 * every deploy. The environment tier reads the union of published claims to
 * evaluate which env-shared services are still consumed — so unlike the env
 * manifest (operator-owned, seed-only), the claim file is YOLO's and
 * reconciles freely.
 */
class PublishAppManifestStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        $desired = Yaml::dump([
            'name' => Manifest::name(),
            'services' => Manifest::services(),
        ]);

        $current = $this->currentClaim();

        if ($current === $desired) {
            return StepResult::SYNCED;
        }

        $this->recordChange(Change::make(
            Paths::s3AppManifestKey(),
            $current === null ? 'absent' : 'out of date',
            'published',
        ));

        if (Arr::get($options, 'dry-run')) {
            return $current === null ? StepResult::WOULD_CREATE : StepResult::WOULD_SYNC;
        }

        try {
            Aws::s3()->putObject([
                'Bucket' => Paths::s3EnvConfigBucket(),
                'Key' => Paths::s3AppManifestKey(),
                'Body' => $desired,
            ]);
        } catch (S3Exception $e) {
            if (S3::isNotFound($e)) {
                throw new RuntimeException(sprintf(
                    'The env config bucket does not exist yet — run `yolo sync:environment %s` first.',
                    Helpers::environment(),
                ), $e->getCode(), $e);
            }

            throw $e;
        }

        return $current === null ? StepResult::CREATED : StepResult::SYNCED;
    }

    /**
     * The currently published claim body, or null when never published — or
     * when the env config bucket itself doesn't exist yet (a greenfield plan
     * pass: the env tier owns the bucket and `sync` orders environment before
     * app, so by this step's apply it exists; a standalone `sync:app` or
     * `deploy` against an unsynced environment fails on the write above with
     * instructions instead).
     */
    protected function currentClaim(): ?string
    {
        try {
            return (string) Aws::s3()->getObject([
                'Bucket' => Paths::s3EnvConfigBucket(),
                'Key' => Paths::s3AppManifestKey(),
            ])['Body'];
        } catch (S3Exception $e) {
            if (S3::isNotFound($e)) {
                return null;
            }

            throw $e;
        }
    }
}
