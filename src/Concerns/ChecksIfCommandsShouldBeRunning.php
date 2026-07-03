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

    /**
     * The human-readable reason this command/step is skipped, or null if it should run.
     */
    public function skipReason(Command|Step $instance): ?string
    {
        // The pre-deploy in-sync gate runs as the deployer tier, which is fenced
        // from the admin-owned env-backed-service state these steps reconcile
        // (the env-shared admin key, env log-group tags). `yolo sync <env>`
        // verifies them; the gate skips them rather than 403.
        if ($instance instanceof SkippedByDeployCheck && DeployCheck::active()) {
            return 'admin-owned reconciler — verified by `yolo sync`, not the deploy gate';
        }

        if ($instance instanceof ExecutesSoloStep && Manifest::isMultitenanted()) {
            return 'solo-only step in a multi-tenant app';
        }

        if ($instance instanceof ExecutesMultitenancyStep && ! Manifest::isMultitenanted()) {
            return 'multi-tenancy step in a solo app';
        }

        if ($instance instanceof ExecutesWebStep && Manifest::isHeadless()) {
            return 'headless app (no ALB / Route 53 / domain)';
        }

        return null;
    }
}
