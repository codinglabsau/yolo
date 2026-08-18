<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\CloudWatchLogs\WafLogGroup;

/**
 * Tears down the env WAF request-log group. Runs after TeardownWebAclStep —
 * deleting the web ACL removes its logging configuration, leaving the group
 * unreferenced.
 */
class TeardownWafLogGroupStep extends TeardownStep
{
    protected function resource(): WafLogGroup
    {
        return new WafLogGroup();
    }
}
