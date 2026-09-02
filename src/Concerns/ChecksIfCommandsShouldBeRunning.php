<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\DeployCheck;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Commands\Command;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;
use Codinglabs\Yolo\Contracts\ExecutesSoloStep;
use Codinglabs\Yolo\Contracts\SkippedByDeployCheck;
use Codinglabs\Yolo\Contracts\ExecutesMultitenancyStep;

trait ChecksIfCommandsShouldBeRunning
{
    public function shouldBeRunning(Command|Step $instance): bool
    {
        return $this->skipReason($instance) === null;
    }

    public function skipReason(Command|Step $instance): ?string
    {
        // The deploy gate runs as the deployer tier, fenced from the admin-owned
        // state these steps reconcile — skip rather than 403.
        if ($instance instanceof SkippedByDeployCheck && DeployCheck::active()) {
            return 'admin-owned reconciler — verified by `yolo sync`, not the deploy gate';
        }

        // Keyed on tenants being declared, not the mode: a landlord-only
        // `multitenancy` block has one scope, so it takes the solo shape.
        if ($instance instanceof ExecutesSoloStep && Manifest::hasTenants()) {
            return 'single-scope step in an app with tenants';
        }

        if ($instance instanceof ExecutesMultitenancyStep && ! Manifest::hasTenants()) {
            return 'per-tenant step in an app with no tenants';
        }

        if ($instance instanceof ExecutesWebStep && Manifest::isHeadless()) {
            return 'headless app (no ALB / Route 53 / domain)';
        }

        return null;
    }
}
