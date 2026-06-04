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

it('supervises a known role so SIGTERM can be trapped and drained', function () {
    $script = generatedEntrypointScript();

    expect($script)->toContain('$cmd &');
    expect($script)->toContain('child=$!');
    expect($script)->toContain('trap drain TERM');
    expect($script)->toContain('wait "$child"');
});

it('dispatches the web role to supervisord and bundles the scheduler drain for a plain web app', function () {
    $script = generatedEntrypointScript();

    expect($script)->toContain("web)       cmd='supervisord -c /etc/supervisord.conf -n'");
    // No standalone queue/scheduler service → no extra cmd branches.
    expect($script)->not->toContain('queue)     cmd=');
    expect($script)->not->toContain('scheduler) cmd=');
    // The web container hosts the scheduler, so its drain halts cron and waits.
    expect($script)->toContain('supervisorctl -c /etc/supervisord.conf stop scheduler');
    expect($script)->toContain("pgrep -f 'artisan schedule:run'");
});

it('execs an unknown command directly instead of booting the web server', function () {
    $script = generatedEntrypointScript();

    // A one-off ecs:RunTask passes its command (e.g. `sh -c "…migrate…"`) as args
    // to the fixed entrypoint — ECS can override the command but not the entrypoint.
    // The catchall must exec it, never fall through to supervisord (the hang bug).
    expect($script)->toContain('exec "$@"');
    expect($script)->not->toContain("*)         cmd='supervisord");
});

it('runs a standalone queue under supervisord when it co-hosts the scheduler', function () {
    writeManifest([
        'apex' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => [], 'queue' => []],
    ]);

    $script = generatedEntrypointScript();

    // queue:work + crond is two processes → supervisord, drained like web (cron first).
    expect($script)->toContain("queue)     cmd='supervisord -c /app/docker/supervisord.queue.conf -n'");
    expect($script)->toContain('supervisorctl -c /app/docker/supervisord.queue.conf stop scheduler');
    // The web container no longer hosts the scheduler — its drain is a plain sleep.
    expect($script)->toContain("sleep 10\n");
    expect($script)->not->toContain('scheduler) cmd=');
});

it('runs a standalone queue as a single worker process when the scheduler is its own service', function () {
    writeManifest([
        'apex' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => [], 'queue' => [], 'scheduler' => []],
    ]);

    $script = generatedEntrypointScript();

    // Queue alone → exec'd worker (no supervisord); the scheduler is its own crond.
    expect($script)->toContain("queue)     cmd='php artisan queue:work");
    expect($script)->not->toContain('supervisord -c /app/docker/supervisord.queue.conf');
    expect($script)->toContain("scheduler) cmd='crond");
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
    // The queue still rides the web container; the web drain is a plain sleep.
    expect($script)->toContain("sleep 10\n");
    expect($script)->not->toContain('queue)     cmd=');
});

it('drains for the web shutdown-grace-period before forwarding the stop', function () {
    // Extract the scheduler so the web drain is the plain ALB-window sleep.
    writeManifest([
        'apex' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => [], 'scheduler' => []],
    ]);

    expect(generatedEntrypointScript())->toContain("sleep 10\n");
});

it('tracks the manifest web shutdown-grace-period for the drain duration', function () {
    writeManifest([
        'apex' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['shutdown-grace-period' => 45], 'scheduler' => []],
    ]);

    expect(generatedEntrypointScript())->toContain("sleep 45\n");
});

it('omits the ALB drain window when headless — no target to drain', function () {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['shutdown-grace-period' => 45], 'scheduler' => []],
    ]);

    $script = generatedEntrypointScript();

    // Still supervises + traps so the stop is forwarded cleanly, just no lame-duck
    // ALB sleep window.
    expect($script)->not->toContain('sleep 45');
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
