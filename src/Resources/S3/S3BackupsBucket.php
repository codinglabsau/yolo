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
 * Dumps never share a bucket with logs (external write principal, bucket-wide
 * expiry) or config (deploy-readable): each app's task role writes only its own
 * prefix and reads none, so a compromised container can't exfiltrate another
 * app's data or even its own history. Lifecycle is the retention model (35 days,
 * visible in the bucket rules); versioning stays on purely as tamper armour —
 * the write-only producer can't destroy an existing object, only stack a
 * version on top. Corollary: a producer that silently stops leaves an emptying
 * bucket, so the offsite freshness probe is load-bearing.
 *
 * Deliberately NOT Deletable: a dump's entire purpose is to survive the loss of
 * everything else. An abandoned bucket costs cents and is removed by hand.
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

    public function synchroniseConfiguration(bool $apply = true): array
    {
        return [
            ...$this->reconcilePublicAccessBlock($apply),
            ...$this->reconcileVersioning($apply),
            ...$this->reconcileDumpRetentionLifecycle($apply),
        ];
    }

    /**
     * Noncurrent versions only exist when something overwrote or deleted an
     * object — tampering, a same-day rerun, or expiry itself — so the second rule
     * sweeps them and the delete markers expiry leaves behind.
     *
     * @return array<int, Change>
     */
    protected function reconcileDumpRetentionLifecycle(bool $apply): array
    {
        // This schedules data for deletion, so it may only ever land on a *-backups
        // bucket — a refactor wiring it elsewhere hard-fails instead.
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
