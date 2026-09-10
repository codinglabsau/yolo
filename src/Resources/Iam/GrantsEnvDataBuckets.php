<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Services\Lifecycle;

/**
 * The bucket set the env-wide data documents grant on: the YOLO-named wildcard
 * plus every bring-your-own bucket the admin-published claims name. One home,
 * because read and write must agree on the grant surface. Reads S3 (the claims),
 * so it stays off {@see Paths}; a greenfield plan pass reads as no claims.
 */
trait GrantsEnvDataBuckets
{
    /**
     * Bucket ARNs, no object suffix.
     *
     * @return array<int, string>
     */
    protected function dataBucketArns(): array
    {
        return [
            Paths::s3EnvDataBucketsArn(),
            ...array_map(fn (string $bucket): string => 'arn:aws:s3:::' . $bucket, Lifecycle::publishedBuckets()),
        ];
    }
}
