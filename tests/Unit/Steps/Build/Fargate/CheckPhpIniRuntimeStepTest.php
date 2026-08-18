<?php

use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Build\Fargate\CheckPhpIniRuntimeStep;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true],
    ]);
});

it('passes when the built image loads the app php.ini', function (): void {
    $step = new CheckPhpIniRuntimeStep('testing', probe: fn (): true => true);

    expect($step(['app-version' => '26.24.1.1200']))->toBe(StepResult::SUCCESS);
});

it('hard-fails when the built image loads no app php.ini', function (): void {
    // No ini means PHP compile defaults — 2M uploads that ship green and only
    // surface when a user's upload dies, so the build refuses instead.
    $step = new CheckPhpIniRuntimeStep('testing', probe: fn (): false => false);

    expect(fn (): StepResult => $step(['app-version' => '26.24.1.1200']))
        ->toThrow(RuntimeException::class, 'loads no app php.ini');
});

it('probes the built image tag', function (): void {
    $image = null;
    $step = new CheckPhpIniRuntimeStep('testing', probe: function (string $tag) use (&$image): true {
        $image = $tag;

        return true;
    });

    $step(['app-version' => '26.24.1.1200']);

    expect($image)->toEndWith('/yolo-testing-my-app:26.24.1.1200');
});

it('builds a docker probe that asks PHP itself which fragments loaded', function (): void {
    expect(CheckPhpIniRuntimeStep::command('repo:26.24.1.1200'))->toBe([
        'docker', 'run', '--rm', '--entrypoint', 'php', 'repo:26.24.1.1200',
        '-r', 'exit(str_contains((string) php_ini_scanned_files(), "zz-app.ini") ? 0 : 1);',
    ]);
});
