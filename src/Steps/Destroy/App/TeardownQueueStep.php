<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Resources\Sqs\Queue;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;

/**
 * Tears down this app's SQS queue.
 */
class TeardownQueueStep extends TeardownStep
{
    protected function resource(): Queue
    {
        return new Queue(Helpers::keyedResourceName());
    }
}
