<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\CloudWatch\AlertAlarm;

/**
 * Constructed bare: deletion is by name only, and the target group it watched
 * may already be gone.
 */
class TeardownWebAlertAlarmStep extends TeardownStep implements ExecutesWebStep
{
    protected function resource(): AlertAlarm
    {
        return AlertAlarm::bare('web-5xx', Scope::App);
    }
}
