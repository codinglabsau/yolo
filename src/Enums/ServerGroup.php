<?php

namespace Codinglabs\Yolo\Enums;

enum ServerGroup: string
{
    case WEB = 'web';
    case QUEUE = 'queue';
    case SCHEDULER = 'scheduler';
}
