<?php

namespace Codinglabs\Yolo\Resources\S3;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\S3;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Resources\Resource;

/**
 * Shared Block-Public-Access + versioning reconcilers for the private S3 buckets
 * that must stay locked down and recoverable (the config and logs buckets).
 * Each is read-compared-then-written, so a clean sync is a no-op and a dry-run
 * reports exactly what would change, returning the drift as Change[]. The host
 * resource supplies name() via the Resource contract.
 *
 * @phpstan-require-implements Resource
 */
trait ReconcilesBucketHardening
{
    /**
     * @return array<int, Change>
     */
    protected function reconcilePublicAccessBlock(bool $apply): array
    {
        $desired = Aws::publicAccessBlockConfiguration();

        $current = S3::publicAccessBlock($this->name());

        $changes = collect($desired)
            ->filter(fn (bool $value, string $key): bool => ($current[$key] ?? null) !== $value)
            ->map(fn (bool $value, string $key): Change => Change::make("block-public-access.$key", $current[$key] ?? null, $value))
            ->values()
            ->all();

        if ($changes === [] || ! $apply) {
            return $changes;
        }

        Aws::s3()->putPublicAccessBlock([
            'Bucket' => $this->name(),
            'PublicAccessBlockConfiguration' => $desired,
        ]);

        return $changes;
    }

    /**
     * @return array<int, Change>
     */
    protected function reconcileVersioning(bool $apply): array
    {
        $current = S3::bucketVersioning($this->name());

        if ($current === 'Enabled') {
            return [];
        }

        if ($apply) {
            Aws::s3()->putBucketVersioning([
                'Bucket' => $this->name(),
                'VersioningConfiguration' => ['Status' => 'Enabled'],
            ]);
        }

        return [Change::make('versioning', $current, 'Enabled')];
    }
}
