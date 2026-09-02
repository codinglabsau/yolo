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
 * Auto-discovered runtime provider ({@see CheckYoloInstalledStep} guarantees YOLO ships as a
 * production dependency). On the autoscaling web tier it publishes FrankenPHP pool saturation
 * for burst step-scaling from an after-response hook, in either serving mode; inert everywhere
 * else, since the YOLO_BURST_* environment is set only on the web task definition
 * ({@see SyncTaskDefinitionStep}).
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
            cloudwatch: fn (): CloudWatchClient => new CloudWatchClient([
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

            // Own `yolo:` namespace, so it shadows nothing. The generated crontab carries
            // the schedule; nothing is scheduled here.
            $this->commands([
                Runtime\Commands\DatabaseBackupCommand::class,
            ]);
        }

        if (! $this->burstEnabled()) {
            return;
        }

        // After the response, so the publish rides a request that already holds a CPU slice
        // rather than a separate loop fighting for one. `terminating` fires per response under
        // both FPM and Octane; the reporter debounces internally.
        $this->app->terminating(function (): void {
            $this->app->make(WorkerSaturationReporter::class)->report();
        });

        // Real in-flight concurrency, not the worker gauge that under-reports under a pin.
        // Octane only: a classic tier reads its thread gauges and never consumes the peak, so
        // it shouldn't pay the per-request cache round-trips. pushMiddleware is idempotent, so
        // each Octane worker boot adds it at most once.
        if ($this->burstThreads() === null) {
            $this->app->booted(function (): void {
                $kernel = $this->app->make(HttpKernelContract::class);

                if ($kernel instanceof FoundationHttpKernel) {
                    $kernel->pushMiddleware(TrackInFlightRequests::class);
                }
            });
        }

        // Bounds each SSR render and sheds to CSR while this task is flagged hot. Talks the
        // stable inertia.ssr config/protocol, so it's agnostic to the app's Inertia major;
        // bound in boot() to win over Inertia's own register()-time binding.
        if (interface_exists(Gateway::class)) {
            $this->app->bind(Gateway::class, fn (): Gateway => new SaturationAwareSsrGateway(
                cache: Cache::store(),
                taskId: $this->taskId(),
            ));
        }
    }

    /** Overridable so tests can point at a fixture. */
    protected function manifestPath(): string
    {
        return $this->app->basePath(Helpers::manifestName());
    }

    /**
     * Burst is baked in, not a toggle: the injected ECS service name (the metric dimension the
     * alarm filters on) is the gate. Read via config so `config:cache` bakes it.
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
     * The Fargate microVM exposes more vCPUs than a fractional task is throttled to, so the
     * injected allocation is the only trustworthy denominator. 0.0 (unset) falls back to the
     * cgroup CFS quota.
     */
    private function burstCpu(): float
    {
        return (float) config('yolo.burst.cpu');
    }

    /**
     * The classic tier's thread ceiling ({@see WebThreads}), injected on the web
     * task-def alongside the service name as the saturation denominator. Absent on an
     * Octane tier, whose pool size arrives with every scrape.
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
        // Under ECS awsvpc the hostname is the task ID — unique per task, so the per-task
        // debounce key never collides.
        return gethostname() ?: 'unknown';
    }
}
