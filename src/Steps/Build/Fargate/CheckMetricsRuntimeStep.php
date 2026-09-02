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
 * Burst autoscaling reads FrankenPHP's pool gauges — worker gauges under Octane, thread
 * gauges in classic mode — which Caddy only collects with the `metrics` global option,
 * carried by YOLO's generated Caddyfile in both modes. With it missing nothing errors: no
 * gauges, no datapoints, the burst alarm sits in INSUFFICIENT_DATA and the deploy goes
 * green. Probing the image (not the build dir) also catches a Dockerfile that doesn't
 * copy the build context.
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
            . 'FrankenPHP metrics enabled at /app/docker/Caddyfile. Burst scaling reads its '
            . 'pool gauges, which need it — ensure your Dockerfile copies the build context '
            . '(e.g. `COPY . /app`) so YOLO\'s generated Caddyfile ships, or burst will be silently dark.'
        );
    }

    /**
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
