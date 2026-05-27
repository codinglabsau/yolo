<?php

namespace Codinglabs\Yolo\Steps\Network;

use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Sns\SnsAlarmTopic;

class SyncSnsAlarmTopicStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new SnsAlarmTopic(), $options);
    }
}
