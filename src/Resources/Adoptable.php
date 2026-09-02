<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources;

/**
 * Marker: sync may find this resource pre-existing WITHOUT the `yolo:scope`
 * marker and adopt it by stamping tags. Reserved for singletons beyond YOLO's
 * naming authority — a hosted zone (often pre-created at the registrar), the
 * GitHub OIDC provider (AWS allows one per account). For anything else an
 * unmarked same-named resource is a stranger — possibly another deployment
 * tool's live infrastructure — and sync refuses to adopt it
 * (see SynchronisesResource::synchroniseOwnedTags()).
 */
interface Adoptable {}
