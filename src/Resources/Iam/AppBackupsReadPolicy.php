<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Deletable;

/** Per-app {@see BackupsReadPolicy}: this app's own dump prefix only. */
class AppBackupsReadPolicy extends BackupsReadPolicy implements Deletable
{
    #[\Override]
    public function scope(): Scope
    {
        return Scope::App;
    }

    #[\Override]
    public function description(): string
    {
        return 'YOLO managed read access to the database dumps of this app';
    }

    #[\Override]
    protected function backupObjectArn(): string
    {
        return sprintf('arn:aws:s3:::%s/%s/*', Paths::s3BackupsBucket(), Manifest::name());
    }
}
