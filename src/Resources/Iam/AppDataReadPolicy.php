<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Deletable;

/**
 * Per-app {@see DataReadPolicy}: this app's data bucket only, whichever mode
 * named it. Pure manifest — no claim read. Only provisioned when the app
 * declares a bucket (an IAM document needs at least one resource).
 */
class AppDataReadPolicy extends DataReadPolicy implements Deletable
{
    #[\Override]
    public function scope(): Scope
    {
        return Scope::App;
    }

    #[\Override]
    public function description(): string
    {
        return 'YOLO managed read access to the data bucket of this app';
    }

    #[\Override]
    protected function dataBucketArns(): array
    {
        return ['arn:aws:s3:::' . Paths::s3AppBucket()];
    }
}
