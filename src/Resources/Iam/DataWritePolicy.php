<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;

/**
 * Write access to the environment's app data — objects in every data bucket, plus
 * reading the database dumps (a restore is a write-side act; nothing observes a
 * dump). Composed on top of {@see DataReadPolicy}, never inclusive of it, so each
 * statement lives in exactly one document.
 *
 * Dumps are read-only even here: the backups bucket is versioned as tamper
 * armour and no tier gets to delete one.
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
        return 'YOLO managed write access to the app data buckets of this environment, and read access to its database dumps';
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
                [
                    'Effect' => 'Allow',
                    'Resource' => $this->backupObjectArn(),
                    'Action' => [
                        's3:GetObject',
                        's3:GetObjectVersion',
                    ],
                ],
            ],
        ];
    }

    protected function backupObjectArn(): string
    {
        return sprintf('arn:aws:s3:::%s/*', Paths::s3BackupsBucket());
    }
}
