<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\S3;

use Codinglabs\Yolo\Aws;

/**
 * A versioned bucket can't be deleted after a plain object sweep — noncurrent
 * versions and delete markers remain, so every version must go.
 */
trait EmptiesBucket
{
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
