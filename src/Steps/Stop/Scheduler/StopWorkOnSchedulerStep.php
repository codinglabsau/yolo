<?php

namespace Codinglabs\Yolo\Steps\Stop\Scheduler;

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServerGroup;
use Symfony\Component\Process\Process;
use Codinglabs\Yolo\Contracts\RunsOnAwsScheduler;
use Codinglabs\Yolo\Concerns\InteractsWithSupervisor;

class StopWorkOnSchedulerStep implements RunsOnAwsScheduler
{
    use InteractsWithSupervisor;

    public function __invoke(): StepResult
    {
        $name = Manifest::name();

        $this->stopSupervisorWorkers();

        // disable scheduling
        Process::fromShellCommandline(
            command: sprintf('rm /etc/cron.d/%s', Helpers::keyedResourceName(ServerGroup::SCHEDULER))
        )->run();

        $i = 0;

        while (true) {
            // wait for any running schedule:run processes to finish
            $process = Process::fromShellCommandline(
                command: "pgrep -f \"php /var/www/$name/artisan schedule:run\""
            );
            $process->run();

            // wait for the running process to finish, but bail after 36 attempts (3 minutes)
            // to avoid an inifinite loop in the beforeInstall CodeDeploy hook.
            if ($process->getOutput() !== '0' || $i > 36) {
                // '0' means a matching process was found; if non-zero, bail
                if ($i > 36) {
                    return StepResult::TIMEOUT;
                }

                break;
            }

            sleep(5);

            $i++;
        }

        return StepResult::SUCCESS;
    }
}
