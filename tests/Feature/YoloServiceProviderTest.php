<?php

declare(strict_types=1);

use Tests\TestbenchCase;
use Illuminate\Contracts\Http\Kernel;
use Codinglabs\Yolo\Runtime\WorkerSaturationReporter;
use Codinglabs\Yolo\Runtime\Http\TrackInFlightRequests;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;

uses(TestbenchCase::class);

afterEach(function (): void {
    putenv('YOLO_BURST_SERVICE');
    putenv('YOLO_BURST_THREADS');
    putenv('APP_RUNNING_IN_CONSOLE');
});

/** The reporter's private ceiling, read back so the env → constructor wiring is pinned. */
function reporterThreadCeiling(WorkerSaturationReporter $reporter): ?int
{
    return (new ReflectionProperty($reporter, 'threadCeiling'))->getValue($reporter);
}

/**
 * The suite runs under the `cli` SAPI, so every booted app would look like a console
 * process and skip the HTTP-only burst wiring. This is the framework's own override for
 * that decision, so a test can boot an app that reports itself as serving a request.
 */
function servingHttp(): void
{
    putenv('APP_RUNNING_IN_CONSOLE=false');
}

/** A reporter stand-in, so terminate() never constructs the real AWS/cgroup stack. */
function reporterSpy(): object
{
    return new class()
    {
        public bool $reported = false;

        public function report(): void
        {
            $this->reported = true;
        }
    };
}

it('wires the classic-mode thread ceiling from the injected env into the reporter', function (): void {
    putenv('YOLO_BURST_SERVICE=svc');
    putenv('YOLO_BURST_THREADS=16');
    $this->refreshApplication();

    expect(reporterThreadCeiling($this->app->make(WorkerSaturationReporter::class)))->toBe(16);
});

it('leaves the reporter without a thread ceiling on an Octane tier', function (): void {
    putenv('YOLO_BURST_SERVICE=svc');
    $this->refreshApplication();

    expect(reporterThreadCeiling($this->app->make(WorkerSaturationReporter::class)))->toBeNull();
});

it('does not push the in-flight tracking middleware on a classic tier, which never reads the peak', function (): void {
    servingHttp();
    putenv('YOLO_BURST_SERVICE=svc');
    putenv('YOLO_BURST_THREADS=16');
    $this->refreshApplication();

    $kernel = $this->app->make(Kernel::class);
    assert($kernel instanceof FoundationHttpKernel);

    expect($kernel->hasMiddleware(TrackInFlightRequests::class))->toBeFalse();
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
    servingHttp();
    putenv('YOLO_BURST_SERVICE=svc');
    $this->refreshApplication();

    $spy = reporterSpy();
    $this->app->instance(WorkerSaturationReporter::class, $spy);

    $this->app->terminate();

    expect($spy->reported)->toBeTrue();
});

it('does not register the terminating hook when no burst service is set', function (): void {
    servingHttp();
    putenv('YOLO_BURST_SERVICE');
    $this->refreshApplication();

    $spy = reporterSpy();
    $this->app->instance(WorkerSaturationReporter::class, $spy);

    $this->app->terminate();

    expect($spy->reported)->toBeFalse();
});

it('does not register the terminating hook in a console process, which serves no requests', function (): void {
    // The web image runs the scheduler and queue workers beside the web server, so they
    // boot with the same burst environment. `terminating` fires at the end of a console
    // command too, and a scrape from there reads a pool that process never served.
    putenv('YOLO_BURST_SERVICE=svc');
    $this->refreshApplication();

    expect($this->app->runningInConsole())->toBeTrue();

    $spy = reporterSpy();
    $this->app->instance(WorkerSaturationReporter::class, $spy);

    $this->app->terminate();

    expect($spy->reported)->toBeFalse();
});

it('does not push the in-flight tracking middleware in a console process', function (): void {
    putenv('YOLO_BURST_SERVICE=svc');
    $this->refreshApplication();

    $kernel = $this->app->make(Kernel::class);
    assert($kernel instanceof FoundationHttpKernel);

    expect($kernel->hasMiddleware(TrackInFlightRequests::class))->toBeFalse();
});

it('pushes the in-flight tracking middleware onto the web kernel when burst is on', function (): void {
    servingHttp();
    putenv('YOLO_BURST_SERVICE=svc');
    $this->refreshApplication();

    $kernel = $this->app->make(Kernel::class);
    assert($kernel instanceof FoundationHttpKernel);

    expect($kernel->hasMiddleware(TrackInFlightRequests::class))->toBeTrue();
});

it('does not push the in-flight tracking middleware when no burst service is set', function (): void {
    servingHttp();
    putenv('YOLO_BURST_SERVICE');
    $this->refreshApplication();

    $kernel = $this->app->make(Kernel::class);
    assert($kernel instanceof FoundationHttpKernel);

    expect($kernel->hasMiddleware(TrackInFlightRequests::class))->toBeFalse();
});
