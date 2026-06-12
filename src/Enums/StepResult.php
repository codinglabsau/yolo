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

    // A sync step that produces a pushed artefact (a docker image) rather than
    // reconciling a live AWS resource — the plan computes the content tag
    // without Docker; apply runs the build.
    case BUILT;
    case WOULD_BUILD;

    case CUSTOM_MANAGED;
    case TIMEOUT;
    case SKIPPED;
    case MANIFEST_INVALID;
}
