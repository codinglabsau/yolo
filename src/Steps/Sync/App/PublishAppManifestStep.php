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
 * The env tier reads the union of published claims to flag declared-but-idle
 * services and to refuse removing a service apps still consume — never to gate
 * provisioning. Manifests carry no secrets, so the whole environment block
 * ships. Unlike the operator-owned env manifest, the claim file is YOLO's and
 * reconciles freely.
 */
class PublishAppManifestStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        // `name` first and `services` last in normalised list shape — the env
        // tier's claim parser requires both.
        $desired = Yaml::dump([
            'name' => Manifest::name(),
            ...Arr::except(Manifest::current()['environments'][Helpers::environment()] ?? [], ['services']),
            'services' => Manifest::services(),
        ], 10, 2);

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
     * Null when the env config bucket itself doesn't exist yet (greenfield plan
     * pass — `sync` orders environment before app, so by apply it exists; a
     * standalone `sync:app` against an unsynced env fails on the write above).
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
