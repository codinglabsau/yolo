<?php

namespace Codinglabs\Yolo\Enums;

enum DeploymentGroups: string
{
    case WEB = 'web';
    case QUEUE = 'queue';
    case SCHEDULER = 'scheduler';
}
