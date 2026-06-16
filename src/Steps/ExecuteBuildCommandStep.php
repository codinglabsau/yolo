<?php

namespace Codinglabs\Yolo\Steps;

use Dotenv\Dotenv;
use Codinglabs\Yolo\Paths;
use Illuminate\Support\Str;
use Codinglabs\Yolo\Enums\StepResult;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Codinglabs\Yolo\Concerns\RunsProcess;
use Codinglabs\Yolo\Contracts\LongRunning;
use Codinglabs\Yolo\Contracts\RunsOnBuild;
use Codinglabs\Yolo\Contracts\ExecutesCommandStep;

class ExecuteBuildCommandStep implements ExecutesCommandStep, LongRunning, RunsOnBuild
{
    use RunsProcess;

    public function __construct(protected string $environment, protected string $command, protected $filesystem = new Filesystem()) {}

    public function __invoke(array $options = []): StepResult
    {
        // parse the AWS .env version, extracting some env values and overloading
        // and VITE_* keys so 'vite build' works as expected. This is preferred
        // to loading the entire .env because we don't want to accidentally
        // call important services from our build pipeline.
        $dotenv = Dotenv::parse($this->filesystem->get(Paths::build(".env.$this->environment.tmp")));

        $process = new Process(
            command: explode(' ', $this->command),
            cwd: Paths::build(),
            env: [
                ...collect($dotenv)
                    ->filter(fn ($value, $key): bool => in_array($key, [
                        'APP_ENV', // for npm
                        'ASSET_URL', // for vite
                    ]) || Str::startsWith($key, 'VITE_'))
                    ->toArray(),
                ...[
                    'CACHE_DRIVER' => 'null',
                ],
            ],
            timeout: null
        );

        $this->runProcess($process);

        return StepResult::SUCCESS;
    }

    public function name(): string
    {
        return $this->command;
    }

    public function patienceMessage(): string
    {
        return sprintf('Running `%s`', $this->command);
    }
}
