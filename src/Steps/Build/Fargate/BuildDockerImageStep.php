<?php

namespace Codinglabs\Yolo\Steps\Build\Fargate;

use Codinglabs\Yolo\Paths;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Symfony\Component\Process\Process;
use Codinglabs\Yolo\Resources\Fargate\EcrRepository;

class BuildDockerImageStep implements Step
{
    public function __construct(protected string $environment) {}

    public function __invoke(array $options): StepResult
    {
        $process = new Process(
            command: static::command(Arr::get($options, 'app-version'), (new EcrRepository())->uri()),
            // BuildKit is required for --cache-to; it's the default on Docker 23+
            // but force it so older daemons don't choke on the flag.
            env: ['DOCKER_BUILDKIT' => '1'],
            timeout: null,
        );

        $process->mustRun();

        return StepResult::SUCCESS;
    }

    /**
     * Seed the build from the last pushed image's inline cache so a cold builder
     * (CI, or after a local BuildKit GC) pulls the compiled layers — chiefly the
     * ~2-minute install-php-extensions layer — instead of rebuilding them. The
     * cache rides inside the image we already push (type=inline), so it lives in
     * the app's own ECR repo with no extra infrastructure. A missing :latest on
     * the first build is a no-op, not an error.
     *
     * @return array<int, string>
     */
    public static function command(string $appVersion, string $repository): array
    {
        return [
            'docker', 'build',
            '--platform', Manifest::get('tasks.web.platform', 'linux/amd64'),
            '--file', Paths::build(Manifest::get('tasks.web.dockerfile', 'Dockerfile')),
            '--cache-from', "$repository:latest",
            '--cache-to', 'type=inline',
            '--tag', "$repository:$appVersion",
            '--tag', "$repository:latest",
            Paths::build(),
        ];
    }
}
