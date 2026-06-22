<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Sns\SnsAlarmTopic;

/**
 * Tears down the env SNS alarm topic. The alarms that published to it are
 * app/autoscaling-scoped and gone with their apps by now.
 */
class TeardownSnsAlarmTopicStep extends TeardownStep
{
    protected function resource(): SnsAlarmTopic
    {
        return new SnsAlarmTopic();
    }
}
