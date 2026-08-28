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
 * app on dated keys (`{app}/{database}/{Y-m-d}.sql.zst`). Dumps never share
 * a bucket with logs (external write principal, bucket-wide expiry) or config
 * (deploy-readable) — they're full database content with their own posture:
 * each app's task role can write only its own prefix and read none, so a
 * compromised container can't exfiltrate another app's data — or even its own
 * history.
 *
 * Lifecycle is the retention model: dated dumps expire after 35 days, plainly
 * visible in the bucket rules. Versioning stays on purely as tamper armour —
 * the producer's write-only grant means it cannot destroy an existing object
 * (an overwrite or delete only stacks a version/marker on top), so history
 * survives a compromised task. The corollary of expiring current objects: a
 * producer that silently stops leaves an emptying bucket, so the offsite
 * freshness probe is load-bearing, not optional.
 *
 * Deliberately NOT Deletable: environment teardown removes what the platform
 * can recreate, and a database dump is the one artefact whose entire purpose
 * is to survive the loss of everything else — same instinct as the never-
 * delete set (RDS, app data bucket). An abandoned backups bucket costs cents
 * and is removed by hand when the data is provably no longer needed.
 */
class S3BackupsBucket implements Resource, SynchronisesConfiguration
{
    use ReconcilesBucketHardening;
    use ResolvesTags;

    public function name(): string
    {
        return Paths::s3BackupsBucket();
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
     * Expire dated dumps after 35 days (retention lives here, visibly, not in
     * versioning), abort abandoned multipart uploads after 7. The second rule
     * is versioning hygiene: noncurrent versions only exist when something
     * overwrote or deleted an object — tampering, a same-day rerun, or expiry
     * itself — so they're swept after 14 days, and the delete markers expiry
     * leaves behind are cleaned up.
     *
     * @return array<int, Change>
     */
    protected function reconcileDumpRetentionLifecycle(bool $apply): array
    {
        // Paranoia gate, mirroring the logs bucket's: this schedules data for
        // deletion, so it may only ever land on a *-backups bucket — a refactor
        // wiring it to any other bucket hard-fails the sync instead.
        if (! str_ends_with($this->name(), '-backups')) {
            throw new IntegrityCheckException(sprintf(
                'Refusing to apply the backup-retention lifecycle to "%s" — it only ever applies to a *-backups bucket.',
                $this->name(),
            ));
        }

        $desired = [
            [
                'ID' => 'expire-backups',
                'Status' => 'Enabled',
                'Filter' => ['Prefix' => ''],
                'Expiration' => ['Days' => 35],
                'AbortIncompleteMultipartUpload' => ['DaysAfterInitiation' => 7],
            ],
            [
                'ID' => 'sweep-noncurrent-backups',
                'Status' => 'Enabled',
                'Filter' => ['Prefix' => ''],
                'Expiration' => ['ExpiredObjectDeleteMarker' => true],
                'NoncurrentVersionExpiration' => ['NoncurrentDays' => 14],
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

        return [Change::make('lifecycle', $current === null ? null : 'present', 'expire dumps after 35 days, sweep noncurrent after 14')];
    }
}
