<?php

namespace Codinglabs\Yolo\Steps\Build\Fargate;

use Closure;
use RuntimeException;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServerGroup;
use Symfony\Component\Process\Process;
use Codinglabs\Yolo\Resources\Ecr\EcrRepository;

/**
 * busybox crond can't run as a non-root supervisord program — it silently
 * ignores crontabs not owned by root and its job children die on a setgroups
 * EPERM — so an image without supercronic deploys green and never fires a job.
 */
class CheckSchedulerRuntimeStep implements Step
{
    public function __construct(
        protected string $environment,
        protected ?Closure $probe = null,
    ) {}

    public function __invoke(array $options): StepResult
    {
        if (! Manifest::schedulerHost() instanceof ServerGroup) {
            return StepResult::SKIPPED;
        }

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
