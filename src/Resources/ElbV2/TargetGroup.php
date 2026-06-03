<?php

namespace Codinglabs\Yolo\Resources\ElbV2;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Aws\ElbV2;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\ShutdownTimings;
use Codinglabs\Yolo\Resources\Ec2\Vpc;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class TargetGroup implements Resource, SynchronisesConfiguration
{
    use ResolvesTags;

    public function name(): string
    {
        return $this->keyedName();
    }

    public function scope(): Scope
    {
        return Scope::App;
    }

    public function exists(): bool
    {
        try {
            ElbV2::targetGroup($this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return ElbV2::targetGroup($this->name())['TargetGroupArn'];
    }

    public function create(): void
    {
        $arn = Aws::elasticLoadBalancingV2()->createTargetGroup([
            'Name' => $this->name(),
            'Protocol' => 'HTTP',
            'Port' => (int) Manifest::get('tasks.web.port', 8000),
            'TargetType' => 'ip',
            'VpcId' => (new Vpc())->arn(),
            'HealthCheckProtocol' => 'HTTP',
            ...static::reconcilableHealthCheck(),
            ...Aws::tags($this->tags()),
        ])['TargetGroups'][0]['TargetGroupArn'];

        // A fresh target group defaults to 300s deregistration; bring it to ours.
        $this->reconcileDeregistrationDelay($arn, apply: true);
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseElbV2Tags($this->arn(), $this->tags(), $apply);
    }

    /**
     * Push managed config onto an existing target group — health-check fields
     * and the deregistration delay. Create sets these once; without this a
     * changed default or manifest override would never reach an already-deployed
     * app, since tag sync doesn't cover them. Each reconcile diffs first so a
     * clean sync makes no needless write, and returns the drifted attributes so
     * sync can report each current → desired comparison. (The service grace
     * period is a separate, service-level setting reconciled by EcsService.)
     */
    public function synchroniseConfiguration(bool $apply = true): array
    {
        $live = ElbV2::targetGroup($this->name());

        return [
            ...$this->reconcileHealthCheck($live, $apply),
            ...$this->reconcileDeregistrationDelay($live['TargetGroupArn'], $apply),
        ];
    }

    /**
     * @param  array<string, mixed>  $live
     * @return array<int, Change>
     */
    protected function reconcileHealthCheck(array $live, bool $apply): array
    {
        $desired = static::reconcilableHealthCheck();

        $current = [
            'HealthCheckPath' => $live['HealthCheckPath'] ?? null,
            'HealthCheckIntervalSeconds' => $live['HealthCheckIntervalSeconds'] ?? null,
            'HealthCheckTimeoutSeconds' => $live['HealthCheckTimeoutSeconds'] ?? null,
            'HealthyThresholdCount' => $live['HealthyThresholdCount'] ?? null,
            'UnhealthyThresholdCount' => $live['UnhealthyThresholdCount'] ?? null,
            'Matcher' => ['HttpCode' => $live['Matcher']['HttpCode'] ?? null],
        ];

        $changes = [];

        foreach ($desired as $key => $value) {
            if ($key === 'Matcher') {
                if (($current['Matcher']['HttpCode'] ?? null) !== ($value['HttpCode'] ?? null)) {
                    $changes[] = Change::make('Matcher.HttpCode', $current['Matcher']['HttpCode'] ?? null, $value['HttpCode'] ?? null);
                }

                continue;
            }

            if (($current[$key] ?? null) !== $value) {
                $changes[] = Change::make($key, $current[$key] ?? null, $value);
            }
        }

        if ($changes === [] || ! $apply) {
            return $changes;
        }

        Aws::elasticLoadBalancingV2()->modifyTargetGroup([
            'TargetGroupArn' => $live['TargetGroupArn'],
            ...$desired,
        ]);

        return $changes;
    }

    /**
     * Cap connection draining at a sane window (default 10s) rather than the AWS
     * default 300s, so a deploy isn't held draining the old task far longer than
     * any real request needs. Bump tasks.web.shutdown-grace-period for apps with genuinely
     * long in-flight requests (uploads, exports, SSE) — anything still in flight
     * when the timer elapses has its connection closed.
     *
     * @return array<int, Change>
     */
    protected function reconcileDeregistrationDelay(string $arn, bool $apply): array
    {
        $desired = (string) $this->deregistrationDelay();

        $current = data_get(
            collect(Aws::elasticLoadBalancingV2()->describeTargetGroupAttributes(['TargetGroupArn' => $arn])['Attributes'])
                ->firstWhere('Key', 'deregistration_delay.timeout_seconds'),
            'Value',
        );

        if ($current === $desired) {
            return [];
        }

        if ($apply) {
            Aws::elasticLoadBalancingV2()->modifyTargetGroupAttributes([
                'TargetGroupArn' => $arn,
                'Attributes' => [
                    ['Key' => 'deregistration_delay.timeout_seconds', 'Value' => $desired],
                ],
            ]);
        }

        return [Change::make('deregistration_delay.timeout_seconds', $current, $desired)];
    }

    public function deregistrationDelay(): int
    {
        // The ALB drains for exactly as long as the web process keeps serving on
        // shutdown — one knob (tasks.web.shutdown-grace-period), no separate delay to tune.
        return ShutdownTimings::webGrace();
    }

    /**
     * The health-check fields create sets and sync keeps current — one source
     * of truth so the two paths can't drift apart. Timeout must stay below the
     * interval (an AWS constraint on ModifyTargetGroup).
     *
     * Defaults are tuned to avoid false-positive failures on a Laravel/Octane
     * app under CPU load: when the FrankenPHP worker pool is saturated the
     * /health probe queues behind in-flight requests and answers slowly (6-7s)
     * rather than failing, so an 8s timeout (still below the 10s interval) keeps
     * a slow-but-alive task in service, and the roomier unhealthy threshold (5)
     * adds cushion. A real deadlock (no response / 30s+) still trips within ~a
     * minute. Capacity is autoscaling's signal, not the health check's. Each
     * field is overridable per app via tasks.web.health-check.* for the rare app
     * that needs a different path or timing.
     *
     * @return array<string, mixed>
     */
    public static function reconcilableHealthCheck(): array
    {
        return [
            'HealthCheckPath' => Manifest::get('tasks.web.health-check.path', '/health'),
            'HealthCheckIntervalSeconds' => (int) Manifest::get('tasks.web.health-check.interval', 10),
            'HealthCheckTimeoutSeconds' => (int) Manifest::get('tasks.web.health-check.timeout', 8),
            'HealthyThresholdCount' => (int) Manifest::get('tasks.web.health-check.healthy-threshold', 2),
            'UnhealthyThresholdCount' => (int) Manifest::get('tasks.web.health-check.unhealthy-threshold', 5),
            'Matcher' => ['HttpCode' => '200'],
        ];
    }
}
