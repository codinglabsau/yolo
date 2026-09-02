<?php

namespace Codinglabs\Yolo\Resources\ElbV2;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Aws\ElbV2;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\ShutdownTimings;
use Codinglabs\Yolo\Resources\Ec2\Vpc;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class TargetGroup implements Deletable, Resource, SynchronisesConfiguration
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
            'Port' => 8000,
            'TargetType' => 'ip',
            'VpcId' => (new Vpc())->arn(),
            'HealthCheckProtocol' => 'HTTP',
            ...static::reconcilableHealthCheck(),
            ...Aws::tags($this->tags()),
        ])['TargetGroups'][0]['TargetGroupArn'];

        $this->reconcileDeregistrationDelay($arn, apply: true);
    }

    /**
     * AWS refuses to delete a target group a listener rule still references;
     * teardown deletes this app's rule first, so a plain delete is correct here.
     */
    public function delete(): void
    {
        try {
            Aws::elasticLoadBalancingV2()->deleteTargetGroup([
                'TargetGroupArn' => $this->arn(),
            ]);
        } catch (ResourceDoesNotExistException) {
            // arn() resolution raced a concurrent delete — already gone.
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'TargetGroupNotFound') {
                return;
            }

            throw $e;
        }
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseElbV2Tags($this->arn(), $this->tags(), $apply);
    }

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
     * AWS defaults deregistration to 300s, which would hold every deploy draining
     * the old task far longer than any real request needs; anything still in
     * flight when the window elapses has its connection closed.
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
        // One knob: the ALB drains exactly as long as the web process keeps serving on shutdown.
        return ShutdownTimings::webGrace();
    }

    /**
     * Timeout must stay below the interval (an AWS constraint on ModifyTargetGroup).
     * Defaults are tuned so a saturated FrankenPHP worker pool — where /up queues
     * behind in-flight requests and answers slowly rather than failing — stays in
     * service: capacity is autoscaling's signal, not the health check's. A real
     * deadlock still trips within about a minute.
     *
     * @return array<string, mixed>
     */
    public static function reconcilableHealthCheck(): array
    {
        return [
            'HealthCheckPath' => Manifest::get('tasks.web.health-check.path', '/up'),
            'HealthCheckIntervalSeconds' => (int) Manifest::get('tasks.web.health-check.interval', 10),
            'HealthCheckTimeoutSeconds' => (int) Manifest::get('tasks.web.health-check.timeout', 5),
            'HealthyThresholdCount' => (int) Manifest::get('tasks.web.health-check.healthy-threshold', 2),
            'UnhealthyThresholdCount' => (int) Manifest::get('tasks.web.health-check.unhealthy-threshold', 5),
            'Matcher' => ['HttpCode' => '200'],
        ];
    }
}
