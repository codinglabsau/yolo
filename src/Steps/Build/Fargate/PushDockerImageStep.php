<?php

namespace Codinglabs\Yolo\Steps\Build\Fargate;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Symfony\Component\Process\Process;
use Codinglabs\Yolo\Resources\Fargate\EcrRepository;

class PushDockerImageStep implements Step
{
    public function __construct(protected string $environment) {}

    public function __invoke(array $options): StepResult
    {
        $appVersion = Arr::get($options, 'app-version');
        $repository = (new EcrRepository())->uri();

        foreach (["$repository:$appVersion", "$repository:latest"] as $tag) {
            (new Process(['docker', 'push', $tag], timeout: null))->mustRun();
        }

        return StepResult::SUCCESS;
    }
}
