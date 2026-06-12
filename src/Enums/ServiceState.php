<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Enums;

/**
 * The lifecycle state of an env-backed service in an environment, evaluated
 * from the two keys that must both turn for the service to exist: the env
 * manifest OFFERS it (`services.{name}`), and at least one live app CLAIMS it
 * (a published `apps/{app}.yml` listing the service, from an app whose ECS
 * cluster is running tasks).
 */
enum ServiceState
{
    /** Both keys turned (offered ∧ live claim) — sync the service's resources toward the manifest. */
    case Provision;

    /** Not offered-and-claimed, and every live app has published — tear the service's resources down. */
    case Teardown;

    /**
     * The gate is off but a live app hasn't published its claim file, so the
     * claim set is unknowable — unknown state ≠ no claims, so nothing is
     * created or torn down until every live app's next deploy/sync:app
     * populates the registry. That makes the rollout bootstrap-safe: day one
     * nobody has published and existing services hold position.
     */
    case Retain;
}
