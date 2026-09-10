<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Deletable;

/**
 * Per-app {@see DataWritePolicy}: this app's data bucket and its own dump prefix
 * only. Pure manifest — no claim read. Always provisioned: the dump-prefix read
 * keeps the document valid even for an app with no data bucket.
 */
class AppDataWritePolicy extends DataWritePolicy implements Deletable
{
    #[\Override]
    public function scope(): Scope
    {
        return Scope::App;
    }

    #[\Override]
    public function description(): string
    {
        return 'YOLO managed write access to the data bucket of this app, and read access to its database dumps';
    }

    #[\Override]
    public function document(): array
    {
        $document = parent::document();

        if (! Manifest::has('bucket')) {
            array_shift($document['Statement']);
        }

        return $document;
    }

    #[\Override]
    protected function dataBucketArns(): array
    {
        return Manifest::has('bucket') ? ['arn:aws:s3:::' . Paths::s3AppBucket()] : [];
    }

    #[\Override]
    protected function backupObjectArn(): string
    {
        return sprintf('arn:aws:s3:::%s/%s/*', Paths::s3BackupsBucket(), Manifest::name());
    }
}
