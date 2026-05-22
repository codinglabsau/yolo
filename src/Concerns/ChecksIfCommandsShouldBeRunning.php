<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Commands\Command;
use Codinglabs\Yolo\Contracts\RunsOnAws;
use Codinglabs\Yolo\Contracts\RunsOnAwsWeb;
use Codinglabs\Yolo\Contracts\RunsOnAwsQueue;
use Codinglabs\Yolo\Contracts\ExecutesIvsStep;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;
use Codinglabs\Yolo\Contracts\ExecutesSoloStep;
use Codinglabs\Yolo\Contracts\RunsOnAwsScheduler;
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
        if ($instance instanceof ExecutesSoloStep && Manifest::isMultitenanted()) {
            return 'solo-only step in a multi-tenant app';
        }

        if ($instance instanceof ExecutesMultitenancyStep && ! Manifest::isMultitenanted()) {
            return 'multi-tenancy step in a solo app';
        }

        if ($instance instanceof ExecutesWebStep && Manifest::isHeadless()) {
            return 'headless app (no ALB / Route 53 / domain)';
        }

        if ($instance instanceof ExecutesIvsStep && ! Manifest::ivsEnabled()) {
            return 'aws.ivs not enabled in manifest';
        }

        if (Aws::runningInAws()) {
            if ($instance instanceof RunsOnAwsWeb) {
                return Aws::runningInAwsWebEnvironment() ? null : 'not the web environment';
            }

            if ($instance instanceof RunsOnAwsQueue) {
                return Aws::runningInAwsQueueEnvironment() ? null : 'not the queue environment';
            }

            if ($instance instanceof RunsOnAwsScheduler) {
                return Aws::runningInAwsSchedulerEnvironment() ? null : 'not the scheduler environment';
            }

            return $instance instanceof RunsOnAws ? null : 'does not run on AWS instances';
        }

        return $instance instanceof RunsOnAws ? 'only runs on AWS instances' : null;
    }
}
