<?php

declare(strict_types=1);

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Enums\StepResult;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Codinglabs\Yolo\Steps\Build\Fargate\BuildDockerImageStep;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true],
    ]);
});

it('builds with inline cache seeded from the last pushed image', function (): void {
    $repository = '111111111111.dkr.ecr.ap-southeast-2.amazonaws.com/yolo-testing-my-app';

    $command = BuildDockerImageStep::command('26.21.5.0900', $repository);

    // Pulls the previous image's inline cache, and writes this build's cache back in.
    expect($command)->toContain('--cache-from', "$repository:latest");
    expect($command)->toContain('--cache-to', 'type=inline');

    // Still tags both the version and latest.
    expect($command)->toContain('--tag', "$repository:26.21.5.0900");
    expect($command)->toContain("$repository:latest");
});

it('re-resolves the base image every build', function (): void {
    // The scaffolded FROM is a floating tag, so without --pull a builder that has
    // built before keeps whatever base layer it cached while a cold one gets today's.
    // A stale runtime fails at container start, not at build, so the deploy is what
    // breaks.
    $command = BuildDockerImageStep::command('26.21.5.0900', 'repo');

    expect($command)->toContain('--pull');

    // --pull re-resolves FROM only; the layer cache above it still seeds from the
    // last pushed image.
    expect($command)->toContain('--cache-from', 'repo:latest');
});

it('drops the .git placeholder before the context is handed to Docker', function (): void {
    // The scaffolded .dockerignore lists .git, but an app owns that file — so the
    // guarantee that the marker never reaches an image layer stays with YOLO.
    (new Filesystem())->ensureDirectoryExists(Paths::build('.git'));

    $step = new class('testing') extends BuildDockerImageStep
    {
        #[Override]
        protected function runProcess(Process $process): void {}
    };

    expect($step(['app-version' => '26.21.5.0900']))->toBe(StepResult::SUCCESS)
        ->and(is_dir(Paths::build('.git')))->toBeFalse();
});
