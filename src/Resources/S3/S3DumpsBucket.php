<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\S3;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Aws\S3;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;

/**
 * Env-scoped bucket holding the apps' logical database dumps, one prefix per
 * app (`{app}/{database}.sql.zst`, overwritten by each run). Dumps never share
 * a bucket with logs (external write principal, bucket-wide expiry) or config
 * (deploy-readable) — they're full database content with their own posture:
 * each app's task role can write only its own prefix and read none, so a
 * compromised container can't exfiltrate another app's data — or even its own
 * history.
 *
 * Versioning is the retention model: each run overwrites `{database}.sql.zst`
 * in place and the noncurrent versions are the history, swept by lifecycle
 * after 35 days. Current versions never expire — the latest dump is the
 * recovery artefact and must outlive any quiet period.
 *
 * Deliberately NOT Deletable: environment teardown removes what the platform
 * can recreate, and a database dump is the one artefact whose entire purpose
 * is to survive the loss of everything else — same instinct as the never-
 * delete set (RDS, app data bucket). An abandoned dumps bucket costs cents
 * and is removed by hand when the data is provably no longer needed.
 */
class S3DumpsBucket implements Resource, SynchronisesConfiguration
{
    use ReconcilesBucketHardening;
    use ResolvesTags;

    public function name(): string
    {
        return Paths::s3DumpsBucket();
    }

    public function scope(): Scope
    {
        return Scope::Env;
    }

    public function exists(): bool
    {
        return S3::bucketExists($this->name());
    }

    public function arn(): string
    {
        return 'arn:aws:s3:::' . $this->name();
    }

    public function create(): void
    {
        Aws::s3()->createBucket([
            'Bucket' => $this->name(),
        ]);

        Aws::s3()->waitUntil('BucketExists', [
            'Bucket' => $this->name(),
        ]);

        $this->synchroniseTags(apply: true);
        $this->synchroniseConfiguration();
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseS3Tags($this->name(), $this->tags(), $apply);
    }

    /**
     * Reconcile Block Public Access, versioning and the noncurrent-version
     * lifecycle, each read-compared-then-written so a clean sync is a no-op
     * and a dry-run reports exactly what would change.
     */
    public function synchroniseConfiguration(bool $apply = true): array
    {
        return [
            ...$this->reconcilePublicAccessBlock($apply),
            ...$this->reconcileVersioning($apply),
            ...$this->reconcileDumpRetentionLifecycle($apply),
        ];
    }

    /**
     * Sweep noncurrent dump versions after 35 days and abandoned multipart
     * uploads after 7 — bounding the history each overwritten key accretes.
     * No current-version expiry, ever: the latest dump must survive any quiet
     * period (an expired "current" backup is the failure mode this bucket
     * exists to prevent).
     *
     * @return array<int, Change>
     */
    protected function reconcileDumpRetentionLifecycle(bool $apply): array
    {
        // Paranoia gate, mirroring the logs bucket's: this schedules data for
        // deletion, so it may only ever land on a *-dumps bucket — a refactor
        // wiring it to any other bucket hard-fails the sync instead.
        if (! str_ends_with($this->name(), '-dumps')) {
            throw new IntegrityCheckException(sprintf(
                'Refusing to apply the dump-retention lifecycle to "%s" — it only ever applies to a *-dumps bucket.',
                $this->name(),
            ));
        }

        $desired = [
            [
                'ID' => 'expire-noncurrent-dumps',
                'Status' => 'Enabled',
                'Filter' => ['Prefix' => ''],
                'NoncurrentVersionExpiration' => ['NoncurrentDays' => 35],
                'AbortIncompleteMultipartUpload' => ['DaysAfterInitiation' => 7],
            ],
        ];

        $current = S3::lifecycleRules($this->name());

        if (Helpers::documentsEqual($current, $desired)) {
            return [];
        }

        if ($apply) {
            Aws::s3()->putBucketLifecycleConfiguration([
                'Bucket' => $this->name(),
                'LifecycleConfiguration' => ['Rules' => $desired],
            ]);
        }

        return [Change::make('lifecycle', $current === null ? null : 'present', 'expire noncurrent dumps after 35 days')];
    }
}
