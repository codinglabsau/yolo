<?php

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Steps\Build\Fargate\GenerateEntrypointScriptStep;

beforeEach(function () {
    writeManifest([
        'apex' => 'example.com',
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'tasks' => ['web' => []],
    ]);

    is_file(Paths::build('.yolo-entrypoint.sh')) && unlink(Paths::build('.yolo-entrypoint.sh'));
});

function generatedEntrypointScript(): string
{
    (new GenerateEntrypointScriptStep('testing'))();

    return file_get_contents(Paths::build('.yolo-entrypoint.sh'));
}

it('starts with a shebang and fails fast through the deploy-all hooks', function () {
    writeManifest([
        'apex' => 'example.com',
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'tasks' => ['web' => []],
        'deploy-all' => ['php artisan migrate --force', 'php artisan config:cache'],
    ]);

    $script = generatedEntrypointScript();

    expect($script)->toStartWith("#!/bin/sh\n");
    expect($script)->toContain('set -e');
    expect($script)->toContain("php artisan migrate --force\nphp artisan config:cache\n");
});

it('supervises the CMD instead of exec-ing it so SIGTERM can be trapped', function () {
    $script = generatedEntrypointScript();

    expect($script)->not->toContain('exec "$@"');
    expect($script)->toContain('"$@" &');
    expect($script)->toContain('child=$!');
    expect($script)->toContain('trap drain TERM');
    expect($script)->toContain('wait "$child"');
});

it('drains for the deregistration delay before forwarding the stop', function () {
    expect(generatedEntrypointScript())->toContain("sleep 10\n");
});

it('tracks the manifest deregistration delay for the drain duration', function () {
    writeManifest([
        'apex' => 'example.com',
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'tasks' => ['web' => ['deregistration-delay' => 45]],
    ]);

    expect(generatedEntrypointScript())->toContain("sleep 45\n");
});

it('omits the drain sleep when headless — no ALB target to drain', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'tasks' => ['web' => ['deregistration-delay' => 45]],
    ]);

    $script = generatedEntrypointScript();

    // Still supervises + traps so the stop is forwarded cleanly, just no lame-duck sleep.
    expect($script)->not->toContain('sleep');
    expect($script)->toContain('trap drain TERM');
    expect($script)->toContain('kill -TERM "$child"');
});

it('forwards SIGTERM to the child and waits for a clean shutdown', function () {
    $script = generatedEntrypointScript();

    expect($script)->toContain('kill -TERM "$child"');
    // The drain runs the hooks first, then supervises — re-enabling lenient mode
    // so a non-zero wait on shutdown does not abort the script.
    expect($script)->toContain('set +e');
});
