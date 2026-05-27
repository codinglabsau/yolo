<?php

namespace Codinglabs\Yolo\Resources\ElbV2;

use Codinglabs\Yolo\Aws;
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
        $this->reconcileDeregistrationDelay($arn);
    }

    public function synchroniseTags(): void
    {
        Aws::synchroniseElbV2Tags($this->arn(), $this->tags());
    }

    /**
     * Push managed config onto an existing target group — health-check fields
     * and the deregistration delay. Create sets these once; without this a
     * changed default or manifest override would never reach an already-deployed
     * app, since tag sync doesn't cover them. Each reconcile diffs first so a
     * clean sync makes no needless write. (The service grace period is a
     * separate, service-level setting reconciled by EcsService.)
     */
    public function synchroniseConfiguration(): void
    {
        $live = ElbV2::targetGroup($this->name());

        $this->reconcileHealthCheck($live);
        $this->reconcileDeregistrationDelay($live['TargetGroupArn']);
    }

    /**
     * @param  array<string, mixed>  $live
     */
    protected function reconcileHealthCheck(array $live): void
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

        if ($current == $desired) {
            return;
        }

        Aws::elasticLoadBalancingV2()->modifyTargetGroup([
            'TargetGroupArn' => $live['TargetGroupArn'],
            ...$desired,
        ]);
    }

    /**
     * Cap connection draining at a sane window (default 10s) rather than the AWS
     * default 300s, so a deploy isn't held draining the old task far longer than
     * any real request needs. Bump tasks.web.shutdown-grace-period for apps with genuinely
     * long in-flight requests (uploads, exports, SSE) — anything still in flight
     * when the timer elapses has its connection closed.
     */
    protected function reconcileDeregistrationDelay(string $arn): void
    {
        $desired = (string) $this->deregistrationDelay();

        $current = data_get(
            collect(Aws::elasticLoadBalancingV2()->describeTargetGroupAttributes(['TargetGroupArn' => $arn])['Attributes'])
                ->firstWhere('Key', 'deregistration_delay.timeout_seconds'),
            'Value',
        );

        if ($current === $desired) {
            return;
        }

        Aws::elasticLoadBalancingV2()->modifyTargetGroupAttributes([
            'TargetGroupArn' => $arn,
            'Attributes' => [
                ['Key' => 'deregistration_delay.timeout_seconds', 'Value' => $desired],
            ],
        ]);
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
     * @return array<string, mixed>
     */
    public static function reconcilableHealthCheck(): array
    {
        return [
            'HealthCheckPath' => Manifest::get('tasks.web.health-check.path', '/health'),
            'HealthCheckIntervalSeconds' => (int) Manifest::get('tasks.web.health-check.interval', 10),
            'HealthCheckTimeoutSeconds' => (int) Manifest::get('tasks.web.health-check.timeout', 5),
            'HealthyThresholdCount' => (int) Manifest::get('tasks.web.health-check.healthy-threshold', 2),
            'UnhealthyThresholdCount' => (int) Manifest::get('tasks.web.health-check.unhealthy-threshold', 3),
            'Matcher' => ['HttpCode' => '200'],
        ];
    }
}
