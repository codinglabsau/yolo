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
 * Reading the environment's database dumps — kept off {@see DataReadPolicy}
 * because the observer tier never sees a dump. Read-only for every tier: the
 * backups bucket is versioned as tamper armour and no tier gets to delete one.
 */
class DumpsReadPolicy implements Deletable, Resource, SynchronisesConfiguration
{
    use ManagesCustomerPolicy;
    use ResolvesTags;

    public function name(): string
    {
        return $this->keyedName(Iam::DUMPS_READ_POLICY);
    }

    public function scope(): Scope
    {
        return Scope::Env;
    }

    /** IAM Description allows only printable ASCII + Latin-1 (no em dashes or smart quotes) — pinned by IamDescriptionsAreSafeTest. */
    public function description(): string
    {
        return 'YOLO managed read access to the database dumps of this environment';
    }

    public function document(): array
    {
        return [
            'Version' => '2012-10-17',
            'Statement' => [
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
