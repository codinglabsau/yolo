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
 * A missing Node runtime is silent: Inertia degrades to client-side rendering,
 * `/up` stays healthy and the deploy goes green. Probes the image rather than
 * grepping the Dockerfile so a multi-stage `COPY --from` or script install
 * counts. PHP itself is deliberately not asserted — it's the app's to swap, and
 * a missing PHP already crash-loops `octane:start` loudly.
 */
class CheckSsrRuntimeStep implements Step
{
    public function __construct(
        protected string $environment,
        protected ?Closure $probe = null,
    ) {}

    public function __invoke(array $options): StepResult
    {
        if (! Manifest::bundles('ssr')) {
            return StepResult::SKIPPED;
        }

        $image = sprintf('%s:%s', (new EcrRepository())->uri(), Arr::get($options, 'app-version'));

        if (($this->probe ?? $this->imageHasNode(...))($image)) {
            return StepResult::SUCCESS;
        }

        throw new RuntimeException(
            'Build aborted: tasks.web.ssr is on but the built image has no Node runtime. '
            . 'Inertia SSR runs `inertia:start-ssr` under Node — add one to your Dockerfile '
            . '(e.g. `apk add --no-cache nodejs`) or the SSR process will crash-loop and the '
            . 'app will silently fall back to client-side rendering.'
        );
    }

    /**
     * @return array<int, string>
     */
    public static function command(string $image): array
    {
        return ['docker', 'run', '--rm', '--entrypoint', 'sh', $image, '-c', 'command -v node'];
    }

    protected function imageHasNode(string $image): bool
    {
        return (new Process(static::command($image)))->run() === 0;
    }
}
