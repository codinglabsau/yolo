<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;

/**
 * Write access to the environment's app data — objects in every data bucket.
 * Composed on top of {@see DataReadPolicy}, never inclusive of it, so each
 * statement lives in exactly one document. Admin only: developers change data
 * through the app (`yolo run`), never by writing user uploads directly.
 */
class DataWritePolicy implements Deletable, Resource, SynchronisesConfiguration
{
    use GrantsEnvDataBuckets;
    use ManagesCustomerPolicy;
    use ResolvesTags;

    public function name(): string
    {
        return $this->keyedName(Iam::DATA_WRITE_POLICY);
    }

    public function scope(): Scope
    {
        return Scope::Env;
    }

    /** IAM Description allows only printable ASCII + Latin-1 (no em dashes or smart quotes) — pinned by IamDescriptionsAreSafeTest. */
    public function description(): string
    {
        return 'YOLO managed write access to the app data buckets of this environment';
    }

    public function document(): array
    {
        return [
            'Version' => '2012-10-17',
            'Statement' => [
                [
                    'Effect' => 'Allow',
                    'Resource' => array_map(fn (string $arn): string => $arn . '/*', $this->dataBucketArns()),
                    'Action' => [
                        's3:PutObject',
                        's3:DeleteObject',
                        's3:AbortMultipartUpload',
                    ],
                ],
            ],
        ];
    }
}
