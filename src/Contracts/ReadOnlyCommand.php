<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Contracts;

/**
 * Marker for a command that only ever reads AWS (status / audit family). YOLO
 * runs these under the read-only Observer tier — it mints an assumed-role token
 * scoped to the observer policy, so the command is capped to reads by
 * construction even when the developer's own identity is broader.
 */
interface ReadOnlyCommand {}
