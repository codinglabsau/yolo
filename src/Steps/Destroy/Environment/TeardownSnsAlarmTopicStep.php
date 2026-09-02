<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Sns\SnsAlarmTopic;

class TeardownSnsAlarmTopicStep extends TeardownStep
{
    protected function resource(): SnsAlarmTopic
    {
        return new SnsAlarmTopic();
    }
}
