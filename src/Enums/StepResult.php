<?php

namespace Codinglabs\Yolo\Enums;

enum StepResult
{
    case CREATED;
    case SUCCESS;
    case WOULD_CREATE;

    case SYNCED;
    case WOULD_SYNC;

    case DELETED;
    case WOULD_DELETE;

    case CUSTOM_MANAGED;
    case TIMEOUT;
    case SKIPPED;
    case MANIFEST_INVALID;
}
