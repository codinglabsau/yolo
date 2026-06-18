<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\CloudWatchLogs\TaskLogGroup;

/**
 * Tears down this app's task CloudWatch log group.
 */
class TeardownTaskLogGroupStep extends TeardownStep
{
    protected function resource(): TaskLogGroup
    {
        return new TaskLogGroup();
    }
}
