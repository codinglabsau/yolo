<?php

namespace Codinglabs\Yolo\Steps\Build\Fargate;

use Closure;
use RuntimeException;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Symfony\Component\Process\Process;
use Codinglabs\Yolo\Resources\Ecr\EcrRepository;

/**
 * Every app hosts the scheduler somewhere (Manifest::schedulerHost — there's no
 * opt-out), and the scheduler program is supercronic (ProcessCommands::scheduler).
 * This probes the freshly-built image for the supercronic binary and hard-fails
 * the build — before the push — if it can't find one.
 *
 * The hard fail earns its place because the failure it prevents is silent:
 * busybox crond, the cron the base image ships for free, can't run as a non-root
 * supervisord program — it ignores crontabs not owned by root without logging a
 * word, and its forked job children die on a setgroups EPERM before exec — so an
 * image with no working cron deploys green, stays healthy on `/up`, and simply
 * never fires a scheduled job. Probing the actual image (`docker run … command -v
 * supercronic`, matching CheckSsrRuntimeStep) sees every way the binary can land
 * — the resolved base image, a multi-stage `COPY --from`, a script install — with
 * no false negatives.
 */
class CheckSchedulerRuntimeStep implements Step
{
    public function __construct(
        protected string $environment,
        protected ?Closure $probe = null,
    ) {}

    public function __invoke(array $options): StepResult
    {
        $image = sprintf('%s:%s', (new EcrRepository())->uri(), Arr::get($options, 'app-version'));

        if (($this->probe ?? $this->imageHasSupercronic(...))($image)) {
            return StepResult::SUCCESS;
        }

        throw new RuntimeException(
            'Build aborted: the built image has no supercronic binary. The scheduler runs '
            . '`schedule:run` under supercronic (busybox crond can\'t run cron as a non-root '
            . 'user) — add it to your Dockerfile (e.g. `apk add --no-cache supercronic`) or '
            . 'scheduled jobs will never fire.'
        );
    }

    /**
     * The `docker run` probe. `--entrypoint sh` bypasses YOLO's role-dispatch
     * entrypoint; `command -v` is a POSIX-sh builtin resolving against the same
     * PATH the running container sees, so it needs nothing but a shell and exits
     * non-zero when `supercronic` isn't on it.
     *
     * @return array<int, string>
     */
    public static function command(string $image): array
    {
        return ['docker', 'run', '--rm', '--entrypoint', 'sh', $image, '-c', 'command -v supercronic'];
    }

    protected function imageHasSupercronic(string $image): bool
    {
        return (new Process(static::command($image)))->run() === 0;
    }
}
