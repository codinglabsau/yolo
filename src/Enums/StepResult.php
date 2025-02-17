<?php

namespace Codinglabs\Yolo\Enums;

enum StepResult
{
    case CREATED;
    case SUCCESS;
    case WOULD_CREATE;

    case SYNCED;
    case IN_SYNC;
    case OUT_OF_SYNC;
    case WOULD_SYNC;

    case CONDITIONAL;
    case TIMEOUT;
    case SKIPPED;
    case WOULD_SKIP;
    case MANIFEST_INVALID;
}
