<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Commands\Command;
use Codinglabs\Yolo\Contracts\RunsOnAws;
use Codinglabs\Yolo\Contracts\RunsOnAwsWeb;
use Codinglabs\Yolo\Contracts\RunsOnAwsQueue;
use Codinglabs\Yolo\Contracts\RunsOnAwsScheduler;
use Codinglabs\Yolo\Contracts\ExecutesDomainStep;
use Codinglabs\Yolo\Contracts\ExecutesMultitenancyStep;

trait ChecksIfCommandsShouldBeRunning
{
    public function shouldBeRunning(Command|Step $instance): bool
    {
        if ($instance instanceof ExecutesDomainStep) {
            return ! Manifest::isMultitenanted();
        }

        if ($instance instanceof ExecutesMultitenancyStep) {
            return Manifest::isMultitenanted();
        }

        if (Aws::runningInAws()) {
            if ($instance instanceof RunsOnAwsWeb) {
                return Aws::runningInAwsWebEnvironment();
            }

            if ($instance instanceof RunsOnAwsQueue) {
                return Aws::runningInAwsQueueEnvironment();
            }

            if ($instance instanceof RunsOnAwsScheduler) {
                return Aws::runningInAwsSchedulerEnvironment();
            }

            return $instance instanceof RunsOnAws;
        }

        return ! $instance instanceof RunsOnAws;
    }
}
