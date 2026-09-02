<?php

namespace Codinglabs\Yolo\Steps\Build\Fargate;

use Codinglabs\Yolo\Paths;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Enums\StepResult;
use Symfony\Component\Process\Process;
use Codinglabs\Yolo\Concerns\RunsProcess;
use Codinglabs\Yolo\Contracts\LongRunning;
use Codinglabs\Yolo\Resources\Ecr\EcrRepository;

class BuildDockerImageStep implements LongRunning
{
    use RunsProcess;

    public function __construct(protected string $environment) {}

    public function __invoke(array $options): StepResult
    {
        $process = new Process(
            command: static::command(Arr::get($options, 'app-version'), (new EcrRepository())->uri()),
            // --cache-to needs BuildKit; forced so pre-23 daemons don't choke on the flag.
            env: ['DOCKER_BUILDKIT' => '1'],
            timeout: null,
        );

        $this->runProcess($process);

        return StepResult::SUCCESS;
    }

    public function patienceMessage(): string
    {
        return 'Building the container image — a cold cache can take a couple of minutes';
    }

    /**
     * The inline cache rides in the pushed image, so a cold builder (CI) pulls
     * the compiled layers instead of rebuilding them; a missing :latest on the
     * first build is a no-op, not an error.
     *
     * @return array<int, string>
     */
    public static function command(string $appVersion, string $repository): array
    {
        return [
            'docker', 'build',
            '--platform', 'linux/amd64',
            '--file', Paths::build('Dockerfile'),
            '--cache-from', "$repository:latest",
            '--cache-to', 'type=inline',
            '--tag', "$repository:$appVersion",
            '--tag', "$repository:latest",
            Paths::build(),
        ];
    }
}
