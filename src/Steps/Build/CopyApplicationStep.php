<?php

namespace Codinglabs\Yolo\Steps\Build;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Enums\StepResult;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Codinglabs\Yolo\Concerns\RunsProcess;
use Codinglabs\Yolo\Contracts\LongRunning;
use Codinglabs\Yolo\Steps\Build\Fargate\BuildDockerImageStep;

class CopyApplicationStep implements LongRunning
{
    use RunsProcess;

    public function __construct(
        protected string $environment,
        protected $filesystem = new Filesystem()
    ) {}

    public function patienceMessage(): string
    {
        return 'Copying the application into the build directory — a large tree can take a moment';
    }

    public function __invoke(array $options = []): StepResult
    {
        $this->ensureBuildDirectoryExists();

        $process = new Process(
            command: static::command($this->environment),
            cwd: Paths::base(),
            env: [],
            timeout: null
        );

        $this->runProcess($process);

        $this->seedGitPlaceholder();

        return StepResult::SUCCESS;
    }

    /**
     * Mirrors the scaffolded .dockerignore. The agent worktree dirs matter
     * most: a checkout nested there is a whole second copy of the app, vendor
     * included, and once staged it rides straight into the image layer.
     *
     * @return array<int, string>
     */
    public static function command(string $environment): array
    {
        $include = [
            ".env.$environment",
        ];

        $exclude = [
            '.git',
            '.github',
            '.phpunit.cache',
            '.idea',
            '.vscode',
            '.yolo',
            '.claude/worktrees',
            '.cursor/worktrees',
            'public/hot',
            'public/assets/next/*',
            'node_modules',
            'storage/app/*',
            'storage/debugbar',
            'storage/framework/cache/*',
            'storage/framework/sessions/*',
            'storage/framework/testing/*',
            'storage/framework/views/*',
            'storage/logs/*.log',
            'tests',

            '*.DS_Store',
            '.env.*',
            '.pest',
            '.php-cs-fixer.cache',
            '.phpunit.result.cache',
            'public/assets/manifest.json',
        ];

        return [
            'rsync',
            '-avq',
            ...array_map(fn (string $item): string => "--include=$item", $include),
            ...array_map(fn (string $item): string => "--exclude=$item", $exclude),
            '.',
            Paths::build(),
        ];
    }

    /**
     * Tailwind v4's automatic source detection only applies .gitignore rules inside a
     * git repository. The staged copy excludes .git (see the list above), so without a
     * marker the scanner treats the build directory as an unversioned tree and walks
     * all of it — node_modules and vendor included — which turns a seconds-long asset
     * build into a minutes-long one on a dependency-heavy app.
     *
     * An empty directory is enough to restore the repository boundary, with identical
     * compiled output; copying the real .git is not an option, since the history can
     * run to hundreds of megabytes and the Dockerfile copies the whole build context
     * into the image. {@see BuildDockerImageStep} removes the placeholder again before
     * the image is built.
     */
    protected function seedGitPlaceholder(): void
    {
        $this->filesystem->ensureDirectoryExists(Paths::build('.git'));
    }

    protected function ensureBuildDirectoryExists(): void
    {
        if ($this->filesystem->isDirectory(Paths::yolo())) {
            $this->filesystem->deleteDirectory(Paths::yolo());
        }

        $this->filesystem->ensureDirectoryExists(Paths::build());
    }
}
