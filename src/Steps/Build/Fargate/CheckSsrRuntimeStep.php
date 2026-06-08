<?php

namespace Codinglabs\Yolo\Steps\Build\Fargate;

use RuntimeException;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Illuminate\Filesystem\Filesystem;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\warning;

/**
 * When the app bundles Inertia SSR (tasks.web.ssr), the runtime image needs a
 * Node runtime to run `inertia:start-ssr` — but YOLO doesn't own the Dockerfile
 * (it owns the base image + PHP extensions), so it can't install one. This step
 * eyeballs the Dockerfile for a Node runtime and, when it can't see one, warns
 * and asks for confirmation before building.
 *
 * It's a warn-and-confirm, never a hard fail: the regex can't see every way Node
 * lands in an image (a custom base image, a script install), and a genuinely
 * missing runtime only crash-loops the SSR program — Inertia degrades to
 * client-side rendering, so the site still serves. In a non-interactive build
 * (the deploy GHA) the confirm returns its default and the build proceeds, so the
 * heuristic never hangs a pipeline — the warning still lands in the log.
 */
class CheckSsrRuntimeStep implements Step
{
    public function __construct(
        protected string $environment,
        protected Filesystem $filesystem = new Filesystem()
    ) {}

    public function __invoke(array $options = []): StepResult
    {
        if (! Manifest::bundles('ssr')) {
            return StepResult::SKIPPED;
        }

        $dockerfile = Paths::base('Dockerfile');

        if ($this->filesystem->exists($dockerfile) && $this->hasNodeRuntime($this->filesystem->get($dockerfile))) {
            return StepResult::SUCCESS;
        }

        warning(
            'tasks.web.ssr is on but no Node runtime was found in your Dockerfile. Inertia SSR '
            . 'runs `inertia:start-ssr` under Node — add one (e.g. `apk add nodejs`) or the SSR '
            . 'process will crash-loop and the app will fall back to client-side rendering.'
        );

        if (! confirm(label: 'Continue building without a detected Node runtime?', default: true)) {
            throw new RuntimeException('Build aborted: tasks.web.ssr is on but the Dockerfile has no Node runtime.');
        }

        return StepResult::SUCCESS;
    }

    protected function hasNodeRuntime(string $dockerfile): bool
    {
        return preg_match('/\bnode(js)?\b/i', $dockerfile) === 1;
    }
}
