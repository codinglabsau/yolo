<?php

namespace Codinglabs\Yolo\Steps\Build;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class CopyApplicationStep implements Step
{
    public function __construct(
        protected string $environment,
        protected $filesystem = new Filesystem()
    ) {}

    public function __invoke(array $options = []): StepResult
    {
        $this->ensureBuildDirectoryExists();

        $include = [
            // files
            ".env.$this->environment",
        ];

        $exclude = [
            // directories
            '.git',
            '.github',
            '.phpunit.cache',
            '.idea',
            '.yolo',
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

            // files
            '*.DS_Store',
            '.env.*',
            '.php-cs-fixer.cache',
            '.phpunit.result.cache',
            'public/assets/manifest.json',
        ];

        $process = new Process(
            command: [
                'rsync',
                '-avq',
                ...array_map(fn ($item): string => "--include=$item", $include),
                ...array_map(fn (string $item): string => "--exclude=$item", $exclude),
                '.',
                Paths::build(),
            ],
            cwd: Paths::base(),
            env: [],
            timeout: null
        );

        $process->mustRun();

        return StepResult::SUCCESS;
    }

    protected function ensureBuildDirectoryExists(): void
    {
        if ($this->filesystem->isDirectory(Paths::yolo())) {
            $this->filesystem->deleteDirectory(Paths::yolo());
        }

        $this->filesystem->ensureDirectoryExists(Paths::build());
    }
}
