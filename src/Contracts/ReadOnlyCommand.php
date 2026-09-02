<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Contracts;

/**
 * Marks a command run under the Observer tier, so it is capped to reads by
 * construction even when the developer's own identity is broader.
 */
interface ReadOnlyCommand {}
