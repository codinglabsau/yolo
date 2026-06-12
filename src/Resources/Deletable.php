<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources;

/**
 * Opt-in for resources the lifecycle engine can tear down — an env-backed
 * service's resources once the environment no longer runs it (not declared,
 * or nothing using it), or a per-app service resource once the app stops
 * using the service. delete() removes the live resource and everything only
 * it owns (attached policies, targets, its own resource policies); it is only
 * ever called on an existing resource, behind the plan's confirm gate.
 */
interface Deletable
{
    public function delete(): void;
}
