<?php

use Codinglabs\Yolo\Steps\Build\Fargate\BuildDockerImageStep;

beforeEach(function () {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => []],
    ]);
});

it('builds with inline cache seeded from the last pushed image', function () {
    $repository = '111111111111.dkr.ecr.ap-southeast-2.amazonaws.com/yolo-testing-my-app';

    $command = BuildDockerImageStep::command('26.21.5.0900', $repository);

    // Pulls the previous image's inline cache, and writes this build's cache back in.
    expect($command)->toContain('--cache-from', "$repository:latest");
    expect($command)->toContain('--cache-to', 'type=inline');

    // Still tags both the version and latest.
    expect($command)->toContain('--tag', "$repository:26.21.5.0900");
    expect($command)->toContain("$repository:latest");
});

it('respects the manifest platform and dockerfile overrides', function () {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['platform' => 'linux/arm64', 'dockerfile' => 'docker/Dockerfile.prod']],
    ]);

    $command = BuildDockerImageStep::command('26.21.5.0900', 'repo');

    expect($command)->toContain('--platform', 'linux/arm64');
    expect(implode(' ', $command))->toContain('docker/Dockerfile.prod');
});
