<?php

declare(strict_types=1);

use Tests\TestbenchCase;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Codinglabs\Yolo\Runtime\WorkerSaturationReporter;
use Codinglabs\Yolo\Runtime\Http\TrackInFlightRequests;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;

uses(TestbenchCase::class);

afterEach(function (): void {
    putenv('YOLO_BURST_SERVICE');
});

it('registers nothing when no burst service is set', function (): void {
    putenv('YOLO_BURST_SERVICE');
    $this->refreshApplication();

    expect($this->app->bound(WorkerSaturationReporter::class))->toBeFalse();
});

it('binds the saturation reporter when a burst service is set', function (): void {
    putenv('YOLO_BURST_SERVICE=yolo-testing-my-app-web');
    $this->refreshApplication();

    expect($this->app->bound(WorkerSaturationReporter::class))->toBeTrue();
});

it('runs the reporter after the response via the app terminating hook', function (): void {
    putenv('YOLO_BURST_SERVICE=svc');
    $this->refreshApplication();

    // Stand in for the reporter so terminate() doesn't construct the real AWS/cgroup
    // stack — the assertion is purely that the terminating hook invokes report().
    $spy = new class()
    {
        public bool $reported = false;

        public function report(): void
        {
            $this->reported = true;
        }
    };
    $this->app->instance(WorkerSaturationReporter::class, $spy);

    $this->app->terminate();

    expect($spy->reported)->toBeTrue();
});

it('does not register the terminating hook when no burst service is set', function (): void {
    putenv('YOLO_BURST_SERVICE');
    $this->refreshApplication();

    $spy = new class()
    {
        public bool $reported = false;

        public function report(): void
        {
            $this->reported = true;
        }
    };
    $this->app->instance(WorkerSaturationReporter::class, $spy);

    $this->app->terminate();

    expect($spy->reported)->toBeFalse();
});

it('pushes the in-flight tracking middleware onto the web kernel when burst is on', function (): void {
    putenv('YOLO_BURST_SERVICE=svc');
    $this->refreshApplication();

    $kernel = $this->app->make(Kernel::class);
    assert($kernel instanceof FoundationHttpKernel);

    expect($kernel->hasMiddleware(TrackInFlightRequests::class))->toBeTrue();
});

it('does not push the in-flight tracking middleware when no burst service is set', function (): void {
    putenv('YOLO_BURST_SERVICE');
    $this->refreshApplication();

    $kernel = $this->app->make(Kernel::class);
    assert($kernel instanceof FoundationHttpKernel);

    expect($kernel->hasMiddleware(TrackInFlightRequests::class))->toBeFalse();
});

/**
 * The provider registers its scheduling hook via callAfterResolving(Schedule),
 * which fires on every resolution — so forgetting the shared instance and
 * re-resolving replays the hook against the config set here, independent of
 * env plumbing and of whether the boot already resolved a Schedule.
 */
function resolveFreshSchedule(Application $app): Collection
{
    $app->forgetInstance(Schedule::class);

    return collect($app->make(Schedule::class)->events());
}

it('schedules the database backup when a destination is configured', function (): void {
    config()->set('yolo.backup.destination', 'yolo-111111111111-testing-dumps/my-app');

    expect(resolveFreshSchedule($this->app)->contains(
        fn ($event): bool => str_contains((string) $event->command, 'yolo:backup-databases')
    ))->toBeTrue();
});

it('schedules no backup when no destination is configured', function (): void {
    config()->set('yolo.backup.destination', null);

    expect(resolveFreshSchedule($this->app)->contains(
        fn ($event): bool => str_contains((string) $event->command, 'yolo:backup-databases')
    ))->toBeFalse();
});
