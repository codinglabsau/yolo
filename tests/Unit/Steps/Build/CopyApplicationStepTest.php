<?php

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
