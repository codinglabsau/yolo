<?php

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Steps\Build\Fargate\GenerateEntrypointScriptStep;

beforeEach(function () {
    writeManifest([
        'apex' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
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
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => []],
        'deploy-all' => ['php artisan migrate --force', 'php artisan config:cache'],
    ]);

    $script = generatedEntrypointScript();

    expect($script)->toStartWith("#!/bin/sh\n");
    expect($script)->toContain('set -e');
    expect($script)->toContain("php artisan migrate --force\nphp artisan config:cache\n");
});

it('supervises the role command instead of exec-ing it so SIGTERM can be trapped', function () {
    $script = generatedEntrypointScript();

    expect($script)->not->toContain('exec "$@"');
    expect($script)->toContain('$cmd &');
    expect($script)->toContain('child=$!');
    expect($script)->toContain('trap drain TERM');
    expect($script)->toContain('wait "$child"');
});

it('dispatches a web-only app to supervisord with no queue or scheduler branch', function () {
    $script = generatedEntrypointScript();

    expect($script)->toContain("cmd='supervisord -c /etc/supervisord.conf -n'");
    expect($script)->not->toContain('queue)');
    expect($script)->not->toContain('scheduler)');
});

it('adds a queue branch running the worker when the queue is its own service', function () {
    writeManifest([
        'apex' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => [], 'queue' => []],
    ]);

    expect(generatedEntrypointScript())->toContain("queue)     cmd='php artisan queue:work");
});

it('adds a scheduler branch running cron when the scheduler is its own service', function () {
    writeManifest([
        'apex' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => [], 'scheduler' => []],
    ]);

    $script = generatedEntrypointScript();

    expect($script)->toContain("scheduler) cmd='crond");
    expect($script)->toContain("pgrep -f 'artisan schedule:run'");
});

it('drains for the web shutdown-grace-period before forwarding the stop', function () {
    expect(generatedEntrypointScript())->toContain("sleep 10\n");
});

it('tracks the manifest web shutdown-grace-period for the drain duration', function () {
    writeManifest([
        'apex' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['shutdown-grace-period' => 45]],
    ]);

    expect(generatedEntrypointScript())->toContain("sleep 45\n");
});

it('omits the drain sleep when headless — no ALB target to drain', function () {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['shutdown-grace-period' => 45]],
    ]);

    $script = generatedEntrypointScript();

    // Still supervises + traps so the stop is forwarded cleanly, just no lame-duck sleep.
    expect($script)->not->toContain('sleep');
    expect($script)->toContain('trap drain TERM');
    expect($script)->toContain('kill -TERM "$child"');
});

it('does not mention the scheduler when it is disabled', function () {
    expect(generatedEntrypointScript())->not->toContain('schedule:run');
});

it('halts cron and waits out an in-flight schedule:run when the scheduler is enabled', function () {
    writeManifest([
        'apex' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['scheduler' => true]],
    ]);

    $script = generatedEntrypointScript();

    expect($script)->toContain('supervisorctl -c /etc/supervisord.conf stop scheduler');
    expect($script)->toContain("pgrep -f 'artisan schedule:run'");
});

it('forwards SIGTERM to the child and waits for a clean shutdown', function () {
    $script = generatedEntrypointScript();

    expect($script)->toContain('kill -TERM "$child"');
    // The drain runs the hooks first, then supervises — re-enabling lenient mode
    // so a non-zero wait on shutdown does not abort the script.
    expect($script)->toContain('set +e');
});
