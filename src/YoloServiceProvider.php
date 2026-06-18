<?php

declare(strict_types=1);

namespace Codinglabs\Yolo;

use Aws\CloudWatch\CloudWatchClient;
use Illuminate\Support\Facades\Cache;
use Codinglabs\Yolo\Runtime\CgroupCpu;
use Illuminate\Support\ServiceProvider;
use Codinglabs\Yolo\Runtime\MetricsScraper;
use Codinglabs\Yolo\Runtime\WorkerSaturationReporter;
use Codinglabs\Yolo\Steps\Sync\App\SyncTaskDefinitionStep;
use Codinglabs\Yolo\Steps\Build\Fargate\CheckYoloInstalledStep;

/**
 * YOLO's runtime service provider — auto-discovered, it boots with the deployed app
 * ({@see CheckYoloInstalledStep} guarantees
 * YOLO ships as a production dependency). Its job today: on the autoscaling web
 * tier, publish FrankenPHP worker saturation for burst step-scaling from an
 * after-response hook ($app->terminating).
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
        if (! $this->burstEnabled()) {
            return;
        }

        $this->app->singleton(WorkerSaturationReporter::class, fn (): WorkerSaturationReporter => new WorkerSaturationReporter(
            cache: Cache::store(),
            cloudwatch: new CloudWatchClient([
                'version' => 'latest',
                'region' => $this->region(),
                // Tight: this publish runs inline on the worker's terminate path.
                'http' => ['connect_timeout' => 1, 'timeout' => 1],
            ]),
            scraper: new MetricsScraper(),
            cpu: new CgroupCpu(),
            serviceName: $this->burstService(),
            taskId: $this->taskId(),
        ));
    }

    public function boot(): void
    {
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
    }

    /**
     * Burst is baked in, not a user toggle — so there's no "enabled" flag. The one
     * thing the runtime can't derive is the ECS service name the metric is dimensioned
     * by (the alarm filters on it), so YOLO injects that on the autoscaling web
     * task-def only; its presence is the gate. Read via getenv (not env()) so it
     * survives config:cache — it's a real ECS process-env var, not a .env-file one.
     */
    private function burstService(): string
    {
        return (string) getenv('YOLO_BURST_SERVICE');
    }

    private function burstEnabled(): bool
    {
        return $this->burstService() !== '';
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
