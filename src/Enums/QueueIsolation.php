<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Enums;

/**
 * Shared (default) scales to any tenant count but a whale floods the queue;
 * Dedicated gives each tenant its own queue(s) + queue:work program so a whale
 * can't starve the others, but scales to dozens, not hundreds. Orthogonal to a
 * per-tenant `dedicated: true` (its own ECS service).
 */
enum QueueIsolation: string
{
    case Shared = 'shared';
    case Dedicated = 'dedicated';
}
