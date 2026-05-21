<?php

namespace Codinglabs\Yolo\Steps\Build\Fargate;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\AwsLookups;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Symfony\Component\Process\Process;

class PushDockerImageStep implements Step
{
    public function __construct(protected string $environment) {}

    public function __invoke(array $options): StepResult
    {
        $appVersion = Arr::get($options, 'app-version');
        $repository = AwsLookups::ecrRepositoryUri();

        foreach (["$repository:$appVersion", "$repository:latest"] as $tag) {
            (new Process(['docker', 'push', $tag], timeout: null))->mustRun();
        }

        return StepResult::SUCCESS;
    }
}
