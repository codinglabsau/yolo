<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Sns\SnsAlarmTopic;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

class SyncSnsAlarmTopicStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new SnsAlarmTopic(), $options);
    }
}
