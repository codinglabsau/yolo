<?php

namespace Codinglabs\Yolo\Steps\Build\Fargate;

use Codinglabs\Yolo\Paths;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Enums\StepResult;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Codinglabs\Yolo\Concerns\RunsProcess;
use Codinglabs\Yolo\Contracts\LongRunning;
use Codinglabs\Yolo\Resources\Ecr\EcrRepository;
use Codinglabs\Yolo\Steps\Build\CopyApplicationStep;

class BuildDockerImageStep implements LongRunning
{
    use RunsProcess;

    public function __construct(
        protected string $environment,
        protected $filesystem = new Filesystem()
    ) {}

    public function __invoke(array $options): StepResult
    {
        $this->removeGitPlaceholder();

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
     * The placeholder {@see CopyApplicationStep} seeds exists only so the asset build
     * sees a repository boundary; by here the build hooks have run, so it goes before
     * the context is handed to Docker. The scaffolded .dockerignore lists .git too, but
     * an app owns that file and may have replaced it — dropping the directory outright
     * keeps the guarantee with YOLO.
     */
    protected function removeGitPlaceholder(): void
    {
        if ($this->filesystem->isDirectory($placeholder = Paths::build('.git'))) {
            $this->filesystem->deleteDirectory($placeholder);
        }
    }

    /**
     * The inline cache rides in the pushed image, so a cold builder (CI) pulls
     * the compiled layers instead of rebuilding them; a missing :latest on the
     * first build is a no-op, not an error.
     *
     * `--pull` re-resolves the Dockerfile's FROM tag every build. The scaffolded
     * base image is a floating tag, so a machine that has built before happily
     * reuses whatever it cached months ago while CI gets today's — and a base
     * runtime older than the app's expects it to be upgradable, which it isn't
     * from an unprivileged container. The failure surfaces as a crash-looping
     * web tier and a deploy that times out waiting for health, long after the
     * build it came from reported success. It costs one registry round-trip and
     * doesn't touch the layer cache above, which `--cache-from` still seeds.
     *
     * @return array<int, string>
     */
    public static function command(string $appVersion, string $repository): array
    {
        return [
            'docker', 'build',
            '--platform', 'linux/amd64',
            '--pull',
            '--file', Paths::build('Dockerfile'),
            '--cache-from', "$repository:latest",
            '--cache-to', 'type=inline',
            '--tag', "$repository:$appVersion",
            '--tag', "$repository:latest",
            Paths::build(),
        ];
    }
}
