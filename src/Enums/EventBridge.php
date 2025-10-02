<?php

namespace Codinglabs\Yolo\Enums;

enum EventBridge: string
{
    case MEDIA_CONVERT_RULE = 'mediaconvert-rule';
    case MEDIA_CONVERT_RULE_TARGET = 'mediaconvert-rule-target';
}
