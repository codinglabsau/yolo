<?php

namespace Codinglabs\Yolo\Steps\Start\All;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Contracts\RunsOnAws;

class SyncDiskHealthCheckCronStep implements RunsOnAws
{
    public function __invoke(array $options): StepResult
    {
        $cronFile = '/etc/cron.d/yolo-disk-health-check';
        $scriptFile = '/home/ubuntu/disk-health-check.sh';

        if (! Manifest::get('aws.ec2.disk-health-check') || ! $this->hasAutoscalingGroup()) {
            if (file_exists($cronFile)) {
                unlink($cronFile);
            }

            if (file_exists($scriptFile)) {
                unlink($scriptFile);
            }

            return StepResult::SKIPPED;
        }

        file_put_contents(
            $scriptFile,
            file_get_contents(Paths::stubs('disk-health-check.sh.stub'))
        );

        chmod($scriptFile, 0755);

        file_put_contents(
            $cronFile,
            file_get_contents(Paths::stubs('cron/disk-health-check.stub'))
        );

        return StepResult::SYNCED;
    }

    private function hasAutoscalingGroup(): bool
    {
        $serverGroup = $this->serverGroup();

        if (! $serverGroup) {
            return false;
        }

        return (bool) Manifest::get('aws.autoscaling.' . $serverGroup->value);
    }

    private function serverGroup(): ?ServerGroup
    {
        if (Aws::runningInAwsWebEnvironment()) {
            return ServerGroup::WEB;
        }

        if (Aws::runningInAwsQueueEnvironment()) {
            return ServerGroup::QUEUE;
        }

        if (Aws::runningInAwsSchedulerEnvironment()) {
            return ServerGroup::SCHEDULER;
        }

        return null;
    }
}
