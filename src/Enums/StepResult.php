<?php

declare(strict_types=1);

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

    // A pushed artefact (a docker image) rather than a live AWS resource.
    case BUILT;
    case WOULD_BUILD;

    case TIMEOUT;
    case SKIPPED;
    case MANIFEST_INVALID;
}
