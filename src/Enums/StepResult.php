<?php

namespace Codinglabs\Yolo\Enums;

enum StepResult
{
    case CREATED;
    case SUCCESS;
    case WOULD_CREATE;

    case SYNCED;
    case OUT_OF_SYNC;
    case WOULD_SYNC;

    case CUSTOM_MANAGED;
    case TIMEOUT;
    case SKIPPED;
    case MANIFEST_INVALID;
}
