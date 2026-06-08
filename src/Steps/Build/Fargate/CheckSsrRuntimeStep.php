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
 * When the app bundles Inertia SSR (tasks.web.ssr), the web container runs
 * `inertia:start-ssr` under Node. This probes the freshly-built image for a Node
 * runtime and hard-fails the build — before the push — if it can't find one.
 *
 * It runs the actual image (`docker run --entrypoint sh … command -v node`) rather
 * than grepping the Dockerfile, so it sees every way Node lands — the resolved base
 * image, a multi-stage `COPY --from`, a script install — with no false negatives.
 * That authoritative signal is what earns the hard fail (matching the sibling
 * CheckOctaneInstalledStep): a missing SSR runtime is otherwise silent — Inertia
 * degrades to client-side rendering, the web tier stays healthy on PHP's `/up`, and
 * the deploy goes green while SSR is quietly off.
 *
 * Only the SSR runtime is checked, because only it is manifest-driven and only it
 * fails silently. The base runtime (PHP) is deliberately not asserted here: it
 * isn't YOLO's to dictate — Docker makes it the app's to swap — and a genuinely
 * missing PHP runtime is already loud, crash-looping `octane:start` so the
 * deployment circuit breaker rolls the deploy back.
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
     * The `docker run` probe. `--entrypoint sh` bypasses YOLO's role-dispatch
     * entrypoint; `command -v` is a POSIX-sh builtin resolving against the same
     * PATH the running container sees, so it needs nothing but a shell and exits
     * non-zero when `node` isn't on it.
     *
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
