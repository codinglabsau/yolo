<?php

namespace Codinglabs\Yolo\Steps\Build\Fargate;

use Closure;
use RuntimeException;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Symfony\Component\Process\Process;
use Codinglabs\Yolo\Resources\Ecr\EcrRepository;

/**
 * Burst autoscaling needs FrankenPHP's worker metrics, which Caddy only collects when
 * its top-level `metrics` global option is set. octane:start rebuilds the
 * CADDY_GLOBAL_OPTIONS env var, so YOLO ships a custom Caddyfile carrying that option
 * (GenerateSupervisorConfigStep) and runs it via --caddyfile (ProcessCommands::web).
 * This probes the freshly-built image for that baked Caddyfile and hard-fails the build
 * — before the push — if it's missing or has no metrics directive.
 *
 * The hard fail earns its place because the failure it prevents is silent: with metrics
 * off FrankenPHP registers no gauges, the runtime reporter reads nothing and publishes
 * no datapoint, the burst alarm sits in INSUFFICIENT_DATA, and the deploy still goes green on the
 * target-tracking policies — burst is simply, invisibly, dark (exactly how it shipped
 * broken in #118). Probing the actual image (`docker run … grep`, matching
 * CheckSsrRuntimeStep / CheckSchedulerRuntimeStep) catches both a Caddyfile that never
 * got generated and a Dockerfile that doesn't copy the build context into the image.
 *
 * Only autoscaling Octane apps run this: classic mode never enables worker metrics
 * (burst is a documented no-op there) and a non-autoscaling app has no burst path.
 */
class CheckMetricsRuntimeStep implements Step
{
    public function __construct(
        protected string $environment,
        protected ?Closure $probe = null,
    ) {}

    public function __invoke(array $options): StepResult
    {
        if (! Manifest::usesMetricsCaddyfile()) {
            return StepResult::SKIPPED;
        }

        $image = sprintf('%s:%s', (new EcrRepository())->uri(), Arr::get($options, 'app-version'));

        if (($this->probe ?? $this->imageHasMetricsCaddyfile(...))($image)) {
            return StepResult::SUCCESS;
        }

        throw new RuntimeException(
            'Build aborted: web autoscaling is on but the built image has no Caddyfile with '
            . 'FrankenPHP metrics enabled at /app/docker/Caddyfile. Burst scaling reads worker '
            . 'metrics, which need it — ensure your Dockerfile copies the build context (e.g. '
            . '`COPY . /app`) so YOLO\'s generated Caddyfile ships, or burst will be silently dark.'
        );
    }

    /**
     * The `docker run` probe. `--entrypoint sh` bypasses YOLO's role-dispatch
     * entrypoint; `grep` exits non-zero when the `metrics` directive (or the Caddyfile
     * itself) is absent, so it needs nothing but a shell.
     *
     * @return array<int, string>
     */
    public static function command(string $image): array
    {
        return ['docker', 'run', '--rm', '--entrypoint', 'sh', $image, '-c', 'grep -qE "^[[:space:]]*metrics[[:space:]]*$" /app/docker/Caddyfile'];
    }

    protected function imageHasMetricsCaddyfile(string $image): bool
    {
        return (new Process(static::command($image)))->run() === 0;
    }
}
