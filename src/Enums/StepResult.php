<?php

namespace Codinglabs\Yolo\Enums;

enum StepResult
{
    case SUCCESS;
    case CREATED;
    case SYNCED;
    case CONDITIONAL;
    case TIMEOUT;
    case SKIPPED;
    case WOULD_CREATE;
    case WOULD_SYNC;
}
