<?php

namespace Codinglabs\Yolo\Steps\Build\Fargate;

use Codinglabs\Yolo\Paths;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Symfony\Component\Process\Process;

class BuildDockerImageStep implements Step
{
    public function __construct(protected string $environment) {}

    public function __invoke(array $options): StepResult
    {
        $appVersion = Arr::get($options, 'app-version');
        $repository = AwsResources::ecrRepositoryUri();
        $dockerfile = Manifest::get('tasks.web.dockerfile', 'Dockerfile');

        $process = new Process(
            command: [
                'docker', 'build',
                '--platform', Manifest::get('tasks.web.platform', 'linux/amd64'),
                '--file', Paths::build($dockerfile),
                '--tag', "$repository:$appVersion",
                '--tag', "$repository:latest",
                Paths::build(),
            ],
            timeout: null,
        );

        $process->mustRun();

        return StepResult::SUCCESS;
    }
}
