<?php

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Enums\StepResult;
use Symfony\Component\Process\Process;
use Codinglabs\Yolo\Steps\Build\CopyApplicationStep;

it('stages the app with the environment env file included and the never-ship trees excluded', function (): void {
    $command = CopyApplicationStep::command('production');

    expect($command[0])->toBe('rsync')
        ->and($command)->toContain('--include=.env.production')
        ->and($command)->toContain('--exclude=.env.*')
        ->and($command)->toContain('--exclude=.git')
        ->and($command)->toContain('--exclude=.claude/worktrees')
        ->and($command)->toContain('--exclude=.cursor/worktrees')
        ->and($command)->toContain('--exclude=node_modules')
        ->and($command)->toContain('--exclude=tests')
        ->and($command)->toContain('--exclude=.yolo');
});

it('excludes everything the scaffolded .dockerignore does', function (): void {
    $ignored = collect(file(__DIR__ . '/../../../../stubs/.dockerignore.stub', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
        ->reject(fn (string $line): bool => str_starts_with($line, '#'))
        ->map(fn (string $line): string => str_replace('**/', '*', $line))
        ->reject(fn (string $line): bool => in_array($line, ['.gitattributes', '.gitignore'], true));

    $excluded = collect(CopyApplicationStep::command('production'))
        ->filter(fn (string $argument): bool => str_starts_with($argument, '--exclude='))
        ->map(fn (string $argument): string => substr($argument, strlen('--exclude=')));

    expect($ignored->diff($excluded)->values()->all())->toBe([]);
});

it('seeds an empty .git in the build directory so the asset build keeps its repository boundary', function (): void {
    // Tailwind v4's source detection only honours .gitignore inside a git repository;
    // without the marker it scans node_modules and vendor in the staged copy.
    $step = new class('testing') extends CopyApplicationStep
    {
        #[Override]
        protected function runProcess(Process $process): void {}
    };

    expect($step())->toBe(StepResult::SUCCESS)
        ->and(is_dir(Paths::build('.git')))->toBeTrue()
        ->and(scandir(Paths::build('.git')))->toBe(['.', '..']);
});
