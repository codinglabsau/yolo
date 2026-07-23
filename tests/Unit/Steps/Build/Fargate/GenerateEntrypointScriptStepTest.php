<?php

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Steps\Build\Fargate\GenerateEntrypointScriptStep;

beforeEach(function (): void {
    writeManifest([
        'domain' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true],
    ]);

    if (is_file(Paths::build('.yolo-entrypoint.sh'))) {
        unlink(Paths::build('.yolo-entrypoint.sh'));
    }
});

function generatedEntrypointScript(): string
{
    (new GenerateEntrypointScriptStep('testing'))();

    return file_get_contents(Paths::build('.yolo-entrypoint.sh'));
}

it('starts with a shebang and fails fast through the deploy-all hooks', function (): void {
    writeManifest([
        'domain' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true],
        'deploy-all' => ['php artisan migrate --force', 'php artisan config:cache'],
    ]);

    $script = generatedEntrypointScript();

    expect($script)->toStartWith("#!/bin/sh\n");
    expect($script)->toContain('set -e');
    expect($script)->toContain("php artisan migrate --force\nphp artisan config:cache\n");
});

it('supervises a known role so SIGTERM can be trapped and drained', function (): void {
    $script = generatedEntrypointScript();

    expect($script)->toContain('$cmd &');
    expect($script)->toContain('child=$!');
    expect($script)->toContain('trap drain TERM');
    expect($script)->toContain('wait "$child"');
});

it('dispatches the web role to supervisord and signals the bundled scheduler before the ALB window', function (): void {
    $script = generatedEntrypointScript();

    expect($script)->toContain("web)       cmd='supervisord -c /etc/supervisord.conf -n'");
    // No standalone queue/scheduler service → no extra cmd branches.
    expect($script)->not->toContain('queue)     cmd=');
    expect($script)->not->toContain('scheduler) cmd=');
    // The web container hosts the scheduler: supercronic is signalled before the
    // drain window so no new schedule:run launches while web keeps serving —
    // backgrounded, so waiting out the in-flight run overlaps the window instead
    // of delaying the forward.
    expect($script)->toContain("supervisorctl -c /etc/supervisord.conf stop scheduler >/dev/null 2>&1 &\n");
    expect($script)->toContain("sleep 15\n");
});

it('execs an unknown command directly instead of booting the web server', function (): void {
    $script = generatedEntrypointScript();

    // A one-off ecs:RunTask passes its command (e.g. `sh -c "…migrate…"`) as args
    // to the fixed entrypoint — ECS can override the command but not the entrypoint.
    // The catchall must exec it, never fall through to supervisord (the hang bug).
    expect($script)->toContain('exec "$@"');
    expect($script)->not->toContain("*)         cmd='supervisord");
});

it('runs a standalone queue under supervisord when it co-hosts the scheduler', function (): void {
    writeManifest([
        'domain' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'queue' => true],
    ]);

    $script = generatedEntrypointScript();

    // queue:work + supercronic is two processes → supervisord. No drain branch
    // for the role: with no ALB window holding the forward back, the immediate
    // SIGTERM reaches supervisord, which signals both programs at once.
    expect($script)->toContain("queue)     cmd='supervisord -c /app/docker/supervisord.queue.conf -n'");
    expect($script)->not->toContain('stop scheduler');
    // The web container no longer hosts the scheduler — its drain is a plain sleep.
    expect($script)->toContain("sleep 15\n");
    expect($script)->not->toContain('scheduler) cmd=');
});

it('runs a standalone queue as a single worker process when the scheduler is its own service', function (): void {
    writeManifest([
        'domain' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'queue' => true, 'scheduler' => true],
    ]);

    $script = generatedEntrypointScript();

    // Queue alone → exec'd worker (no supervisord); the scheduler is its own supercronic.
    expect($script)->toContain("queue)     cmd='php artisan queue:work");
    expect($script)->not->toContain('supervisord -c /app/docker/supervisord.queue.conf');
    expect($script)->toContain("scheduler) cmd='supercronic");
});

it('adds a scheduler branch running cron when the scheduler is its own service', function (): void {
    writeManifest([
        'domain' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'scheduler' => true],
    ]);

    $script = generatedEntrypointScript();

    // supercronic stops scheduling and waits out the in-flight run on SIGTERM,
    // so the generic forward is the whole drain — no scheduler drain branch.
    expect($script)->toContain("scheduler) cmd='supercronic");
    expect($script)->not->toContain('stop scheduler');
    // The queue still rides the web container; the web drain is a plain sleep.
    expect($script)->toContain("sleep 15\n");
    expect($script)->not->toContain('queue)     cmd=');
});

it('drains for the web shutdown-grace-period before forwarding the stop', function (): void {
    // Extract the scheduler so the web drain is the plain ALB-window sleep.
    writeManifest([
        'domain' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'scheduler' => true],
    ]);

    expect(generatedEntrypointScript())->toContain("sleep 15\n");
});

it('tracks the manifest web shutdown-grace-period for the drain duration', function (): void {
    writeManifest([
        'domain' => 'example.com',
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['shutdown-grace-period' => 45], 'scheduler' => true],
    ]);

    expect(generatedEntrypointScript())->toContain("sleep 45\n");
});

it('emits no web branch and defaults the role to scheduler for a scheduler-only worker app', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => false, 'queue' => false, 'scheduler' => true],
    ]);

    $script = generatedEntrypointScript();

    // No web tier → no web dispatch, no ALB drain case; a bare container run
    // lands on the app's one real role instead of a web server that isn't there.
    expect($script)->toContain('role="${1:-scheduler}"');
    expect($script)->not->toContain('web)       cmd=');
    expect($script)->not->toContain('supervisord -c /etc/supervisord.conf');
    expect($script)->toContain("scheduler) cmd='supercronic");
    expect($script)->not->toContain('queue)     cmd=');
    // The SIGTERM forward is the whole drain — trap intact, no drain dispatch.
    expect($script)->toContain('trap drain TERM');
    expect($script)->not->toContain('case "$role" in
        web)');
    expect($script)->toContain('kill -TERM "$child"');
    // One-off deploy tasks still exec through the catchall.
    expect($script)->toContain('exec "$@"');
});

it('defaults the role to queue for a web-less worker app whose queue hosts the scheduler', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => false, 'queue' => ['autoscaling' => true]],
    ]);

    $script = generatedEntrypointScript();

    expect($script)->toContain('role="${1:-queue}"');
    // The queue co-hosts the scheduler (two processes) → supervisord with the
    // queue config; no web branch anywhere.
    expect($script)->toContain("queue)     cmd='supervisord -c /app/docker/supervisord.queue.conf -n'");
    expect($script)->not->toContain('web)       cmd=');
    expect($script)->not->toContain('scheduler) cmd=');
});

it('runs a multi-tenant standalone queue under supervisord even when the scheduler is its own service', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        // A solo app with this shape runs a single exec'd worker; multi-tenancy needs
        // supervisord to run one queue:work program per tenant, so it routes there.
        'tasks' => ['web' => true, 'queue' => true, 'scheduler' => true],
        'tenants' => ['acme' => []],
    ]);

    $script = generatedEntrypointScript();

    expect($script)->toContain("queue)     cmd='supervisord -c /app/docker/supervisord.queue.conf -n'");
    expect($script)->not->toContain("queue)     cmd='php artisan queue:work");
});

it('forwards SIGTERM to the child and waits for a clean shutdown', function (): void {
    $script = generatedEntrypointScript();

    expect($script)->toContain('kill -TERM "$child"');
    // The drain runs the hooks first, then supervises — re-enabling lenient mode
    // so a non-zero wait on shutdown does not abort the script.
    expect($script)->toContain('set +e');
});
