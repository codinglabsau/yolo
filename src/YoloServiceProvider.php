<?php

declare(strict_types=1);

namespace Codinglabs\Yolo;

use Inertia\Ssr\Gateway;
use Codinglabs\Yolo\Enums\Service;
use Aws\CloudWatch\CloudWatchClient;
use Illuminate\Support\Facades\Cache;
use Codinglabs\Yolo\Runtime\CgroupCpu;
use Illuminate\Support\ServiceProvider;
use Codinglabs\Yolo\Runtime\MetricsScraper;
use Illuminate\Console\Scheduling\Schedule;
use Codinglabs\Yolo\Runtime\InFlightRequests;
use Codinglabs\Yolo\Runtime\WorkerSaturationReporter;
use Codinglabs\Yolo\Runtime\Http\TrackInFlightRequests;
use Codinglabs\Yolo\Runtime\Ssr\SaturationAwareSsrGateway;
use Codinglabs\Yolo\Steps\Sync\App\SyncTaskDefinitionStep;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;
use Codinglabs\Yolo\Steps\Build\Fargate\CheckYoloInstalledStep;

/**
 * YOLO's runtime service provider — auto-discovered, it boots with the deployed app
 * ({@see CheckYoloInstalledStep} guarantees
 * YOLO ships as a production dependency). Its job today: on the autoscaling web
 * tier, publish FrankenPHP pool saturation for burst step-scaling from an
 * after-response hook ($app->terminating), in either serving mode.
 *
 * It is inert everywhere else. The YOLO_BURST_* environment is set only on the web
 * task definition ({@see SyncTaskDefinitionStep}),
 * so queue/scheduler containers and local/dev apps register nothing — the gate
 * matches the one that ships the metrics Caddyfile and grants PutMetricData.
 */
class YoloServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/yolo.php', 'yolo');

        // Ahead of the burst gate — manifest reads matter on every task role.
        $this->app->singleton(ManifestReader::class, fn (): ManifestReader => ManifestReader::load($this->manifestPath(), $this->app->environment()));

        $this->app->singleton(Runtime\Yolo::class);

        if (! $this->burstEnabled()) {
            return;
        }

        $this->app->singleton(InFlightRequests::class, fn (): InFlightRequests => new InFlightRequests(
            cache: Cache::store(),
            taskId: $this->taskId(),
        ));

        $this->app->singleton(WorkerSaturationReporter::class, fn (): WorkerSaturationReporter => new WorkerSaturationReporter(
            cache: Cache::store(),
            cloudwatch: new CloudWatchClient([
                'version' => 'latest',
                'region' => $this->region(),
                // Tight: this publish runs inline on the worker's terminate path.
                'http' => ['connect_timeout' => 1, 'timeout' => 1],
            ]),
            scraper: new MetricsScraper(),
            cpu: new CgroupCpu(allocatedCores: $this->burstCpu()),
            inFlight: $this->app->make(InFlightRequests::class),
            serviceName: $this->burstService(),
            taskId: $this->taskId(),
            threadCeiling: $this->burstThreads(),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // Manifest-gated, not Scout-config-gated: stock scout.php ships a
            // typesense block for every engine, and another engine's own
            // `scout:reimport` (scout-extended) must never be shadowed.
            if ($this->app->make(ManifestReader::class)->hasService(Service::TYPESENSE)) {
                $this->commands([
                    Runtime\Commands\ScoutHealCommand::class,
                    Runtime\Commands\ScoutReimportCommand::class,
                ]);

                // Scheduled here so a wiped index rebuilds without any app
                // remembering a kernel line; the command is self-locking.
                $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                    if (config('yolo.search.heal') && (array) config('scout.typesense.client-settings', []) !== []) {
                        $schedule->command('scout:heal')->everyFiveMinutes();
                    }
                });
            }

            // The backup executor lives in YOLO's own `yolo:` namespace, so it
            // shadows nothing and registers unconditionally. Nothing schedules
            // it here — the generated crontab carries the schedule (see
            // GenerateSupervisorConfigStep), and `yolo backup:database` runs
            // it on demand.
            $this->commands([
                Runtime\Commands\DatabaseBackupCommand::class,
            ]);
        }

        if (! $this->burstEnabled()) {
            return;
        }

        // Publish worker saturation after the response is sent — so the work rides on a
        // request that already holds a CPU slice, not a separate loop fighting for one
        // on a pinned box. The app `terminating` hook fires per response under both
        // FPM and Octane (boot-registered callbacks ride Octane's post-boot snapshot
        // into every request), and the reporter debounces internally so this is cheap
        // on every request. Harmless in a console/queue boot of the same image —
        // terminating only runs at the end of a handled request.
        $this->app->terminating(function (): void {
            $this->app->make(WorkerSaturationReporter::class)->report();
        });

        // Bracket every web request so the reporter scales on real in-flight concurrency
        // rather than the worker gauge that under-reports under a pin. Pushed once the app
        // has booted (the HTTP kernel is resolvable by then); pushMiddleware is idempotent,
        // so re-running on each Octane worker boot adds it at most once.
        $this->app->booted(function (): void {
            $kernel = $this->app->make(HttpKernelContract::class);

            if ($kernel instanceof FoundationHttpKernel) {
                $kernel->pushMiddleware(TrackInFlightRequests::class);
            }
        });

        // On an Inertia app, swap the SSR gateway for one that bounds each render and sheds
        // to CSR while the burst reporter has flagged this task hot. It talks the stable
        // inertia.ssr config/protocol (not Inertia internals), so it's agnostic to the
        // app's Inertia major (v2 or v3, whose HttpGateway internals differ). Bound in
        // boot() so it wins over Inertia's own register()-time binding; guarded on the
        // package being installed so non-Inertia apps are untouched.
        if (interface_exists(Gateway::class)) {
            $this->app->bind(Gateway::class, fn (): Gateway => new SaturationAwareSsrGateway(
                cache: Cache::store(),
                taskId: $this->taskId(),
            ));
        }
    }

    /**
     * The CLI's BASE_PATH constant doesn't exist in-app; overridable so
     * tests can point at a fixture.
     */
    protected function manifestPath(): string
    {
        return $this->app->basePath(Helpers::manifestName());
    }

    /**
     * Burst is baked in, not a user toggle — so there's no "enabled" flag. The one
     * thing the runtime can't derive is the ECS service name the metric is dimensioned
     * by (the alarm filters on it), so YOLO injects that on the autoscaling web
     * task-def only; its presence is the gate. Sourced from the package config
     * (`config/yolo.php`), which reads the injected env var so `config:cache` bakes it.
     */
    private function burstService(): string
    {
        return (string) config('yolo.burst.service');
    }

    private function burstEnabled(): bool
    {
        return $this->burstService() !== '';
    }

    /**
     * The task's vCPU allocation, injected on the web task-def alongside the service
     * name (see SyncTaskDefinitionStep). The CPU fallback divides usage by it; the
     * Fargate microVM exposes more vCPUs than a fractional task is throttled to, so this
     * injected value is the only trustworthy denominator. 0.0 (unset) lets CgroupCpu fall
     * back to the cgroup CFS quota.
     */
    private function burstCpu(): float
    {
        return (float) config('yolo.burst.cpu');
    }

    /**
     * The classic tier's thread ceiling (`max_threads`), injected on the web task-def
     * alongside the service name — the saturation denominator there, since the scraped
     * `total_threads` gauge reports the floor rather than what the pool may grow to.
     * Absent on an Octane tier, whose pool size arrives with every scrape.
     */
    private function burstThreads(): ?int
    {
        $threads = (int) config('yolo.burst.threads');

        return $threads > 0 ? $threads : null;
    }

    private function region(): string
    {
        return (string) (getenv('AWS_DEFAULT_REGION') ?: getenv('AWS_REGION') ?: 'us-east-1');
    }

    private function taskId(): string
    {
        // Under ECS awsvpc the container hostname is the task ID — stable for the
        // task's life and unique per task, so the per-task debounce key never
        // collides across tasks (each publishes its own datapoint; the alarm takes
        // Maximum across them).
        return gethostname() ?: 'unknown';
    }
}
