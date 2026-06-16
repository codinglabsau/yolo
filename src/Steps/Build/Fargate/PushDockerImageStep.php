<?php

namespace Codinglabs\Yolo\Steps\Build\Fargate;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Enums\StepResult;
use Symfony\Component\Process\Process;
use Codinglabs\Yolo\Concerns\RunsProcess;
use Codinglabs\Yolo\Contracts\LongRunning;
use Codinglabs\Yolo\Resources\Ecr\EcrRepository;

class PushDockerImageStep implements LongRunning
{
    use RunsProcess;

    public function __construct(protected string $environment) {}

    public function __invoke(array $options): StepResult
    {
        $appVersion = Arr::get($options, 'app-version');
        $repository = (new EcrRepository())->uri();

        foreach (["$repository:$appVersion", "$repository:latest"] as $tag) {
            $this->runProcess(new Process(['docker', 'push', $tag], timeout: null));
        }

        return StepResult::SUCCESS;
    }

    public function patienceMessage(): string
    {
        return 'Pushing the image to ECR — uploading layers';
    }
}
