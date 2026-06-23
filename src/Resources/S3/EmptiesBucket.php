<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\S3;

use Codinglabs\Yolo\Aws;

/**
 * Empties a versioned bucket so DeleteBucket can succeed. Shared by every
 * deletable config/logs bucket, all of which reconcile versioning to Enabled on
 * create — so a plain object sweep would leave noncurrent versions and delete
 * markers behind and the delete would fail.
 */
trait EmptiesBucket
{
    /**
     * Delete every object version and delete marker in the bucket, paginating
     * the listing and batching deletes up to S3's per-request limit of 1000
     * entries.
     */
    protected function emptyVersions(): void
    {
        $keyMarker = null;
        $versionIdMarker = null;

        do {
            $page = Aws::s3()->listObjectVersions(array_filter([
                'Bucket' => $this->name(),
                'KeyMarker' => $keyMarker,
                'VersionIdMarker' => $versionIdMarker,
            ]));

            $entries = collect([...$page['Versions'] ?? [], ...$page['DeleteMarkers'] ?? []])
                ->map(fn (array $entry): array => [
                    'Key' => $entry['Key'],
                    'VersionId' => $entry['VersionId'],
                ])
                ->all();

            if ($entries !== []) {
                Aws::s3()->deleteObjects([
                    'Bucket' => $this->name(),
                    'Delete' => ['Objects' => $entries],
                ]);
            }

            if ($page['IsTruncated']) {
                $keyMarker = $page['NextKeyMarker'];
                $versionIdMarker = $page['NextVersionIdMarker'];
            } else {
                $keyMarker = null;
                $versionIdMarker = null;
            }
        } while ($keyMarker !== null);
    }
}
