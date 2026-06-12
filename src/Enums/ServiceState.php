<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Enums;

/**
 * What sync should do about an env-backed service, decided by two facts: the
 * environment manifest declares it (`services.{name}`), and at least one
 * running app uses it (the services each app publishes on deploy/sync:app,
 * counted only while the app has running tasks).
 */
enum ServiceState
{
    /** Declared by the environment and in use by a running app — sync it toward the manifest. */
    case Provision;

    /** Not declared-and-in-use, and every running app has published what it uses — tear it down. */
    case Teardown;

    /**
     * A running app hasn't deployed on this YOLO release yet, so the
     * environment doesn't know what it uses — nothing is created or torn
     * down until every running app has published. That makes the rollout
     * bootstrap-safe: day one nobody has republished, and existing services
     * hold position.
     */
    case Retain;
}
