<?php

namespace Codinglabs\Yolo\Enums;

enum Ssm: string
{
    case AUTOSCALING_GROUP_WEB = 'web';
    case AUTOSCALING_GROUP_SCHEDULER = 'scheduler';
    case AUTOSCALING_GROUP_QUEUE = 'queue';

    case BACKGROUND_WORK_STRATEGY = 'background-work-strategy';
    case BACKGROUND_WORK_STRATEGY_WEB_ONLY = 'web-only';
    case BACKGROUND_WORK_STRATEGY_WEB_AND_BACKGROUND = 'web-background';
    case BACKGROUND_WORK_STRATEGY_WEB_SCHEDULER_QUEUE = 'web-scheduler-queue';
}
