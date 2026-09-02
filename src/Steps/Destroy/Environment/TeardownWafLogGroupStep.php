<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\CloudWatchLogs\WafLogGroup;

/**
 * Runs after TeardownWebAclStep — deleting the web ACL is what removes the
 * logging configuration that references this group.
 */
class TeardownWafLogGroupStep extends TeardownStep
{
    protected function resource(): WafLogGroup
    {
        return new WafLogGroup();
    }
}
