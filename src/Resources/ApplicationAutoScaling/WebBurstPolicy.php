<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\ApplicationAutoScaling;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Aws\CloudWatch;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Resources\Ecs\EcsService;
use Codinglabs\Yolo\Aws\ApplicationAutoScaling;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * The opt-in **burst** scale-out path for the web service: a real-time companion
 * to the {@see WebConcurrencyPolicy} default, for apps that can't wait for the
 * ~60s CloudWatch metric floor on a sudden spike.
 *
 * The target-tracking policies scale on ALB metrics, which are 1-minute resolution
 * — a good signal, but ~1 min behind. This pairs a **step-scaling policy** with a
 * **high-resolution CloudWatch alarm** on a worker-saturation metric the container
 * emits itself: each web task reports its FrankenPHP busy/total worker ratio (an
 * *earlier* signal than the ALB — workers queue before latency even climbs) as a
 * high-res metric, only while it's hot. The alarm evaluates the most-saturated task
 * every 10s and steps the desired count out. Detection drops from ~60s to ~10–15s.
 *
 * The metric arrives via EMF (the container writes a structured log line that
 * CloudWatch Logs auto-extracts), so there's no PutMetricData call, no AWS SDK in
 * the container and no new IAM — the saturation emitter ({@see
 * \Codinglabs\Yolo\Steps\Build\Fargate\GenerateSupervisorConfigStep}) just writes
 * to stdout, which already ships to CloudWatch Logs. Enabling FrankenPHP's metrics
 * endpoint is a single `CADDY_GLOBAL_OPTIONS` env var, set on the web task only
 * when burst is on.
 *
 * Scale-*in* is left entirely to the target-tracking policies (slow, safe) — this
 * is scale-out only, so it can only ever add capacity faster, never fight them.
 * Like {@see QueueScaleToZeroBootstrap} both the policy and its alarm are pure
 * upserts, so this is a reconciler, not a Resource. The alarm can be put before the
 * metric has ever been emitted — it simply sits in INSUFFICIENT_DATA (treated as
 * not-breaching) until the first hot datapoint, so there's no first-sync ordering
 * trap.
 *
 * Note: burst complements, never replaces, warm capacity. Even instant detection
 * still waits ~55s for the new task to boot and pass ALB health, so sub-1-min
 * scale-out needs `min ≥ N`; this just makes the spike that exceeds the warm
 * headroom land faster.
 */
class WebBurstPolicy
{
    /** EMF namespace + metric the saturation emitter writes and this alarm reads — the contract between them. */
    public const string METRIC_NAMESPACE = 'YOLO/Autoscaling';

    public const string METRIC_NAME = 'WorkerSaturation';

    public const string METRIC_DIMENSION = 'ServiceName';

    /** Worker-saturation % at which the alarm trips and the burst steps out. */
    public const int ALARM_THRESHOLD = 80;

    /**
     * The emitter only publishes at or above this saturation %, so the metric (and
     * its cost) is near-zero when the service isn't hot. Below the alarm threshold
     * so the alarm still sees not-breaching datapoints around the trip point.
     */
    public const int EMIT_FLOOR = 70;

    /** High-resolution alarm: a 10s period is the fast end of CloudWatch's range. */
    private const int PERIOD = 10;

    private const int COOLDOWN = 60;

    public function policyName(): string
    {
        return Helpers::keyedResourceName('web-burst-policy');
    }

    public function alarmName(): string
    {
        return Helpers::keyedResourceName('web-worker-saturation');
    }

    /** The web service name the EMF metric is dimensioned by — baked into the emitter too. */
    public static function serviceName(): string
    {
        return (new EcsService(ServerGroup::WEB))->name();
    }

    public function exists(): bool
    {
        return $this->policyExists() && $this->alarmExists();
    }

    /**
     * Provision (or confirm) the step policy + its high-res alarm. The config is
     * static, so drift is simply "either piece is missing"; reported as a Change so
     * the sync step renders WOULD_CREATE / CREATED and survives the only-pending
     * filter.
     *
     * @return array<int, Change>
     */
    public function synchronise(bool $apply): array
    {
        $changes = [];

        if (! $this->policyExists()) {
            $changes[] = Change::make('web burst policy', null, $this->policyName());
        }

        if (! $this->alarmExists()) {
            $changes[] = Change::make('web burst alarm', null, $this->alarmName());
        }

        if ($changes === [] || ! $apply) {
            return $changes;
        }

        $policyArn = Aws::applicationAutoScaling()->putScalingPolicy([
            'PolicyName' => $this->policyName(),
            'ServiceNamespace' => ApplicationAutoScaling::SERVICE_NAMESPACE,
            'ResourceId' => ScalableTarget::resourceId(),
            'ScalableDimension' => ApplicationAutoScaling::SCALABLE_DIMENSION,
            'PolicyType' => 'StepScaling',
            'StepScalingPolicyConfiguration' => [
                'AdjustmentType' => 'ChangeInCapacity',
                'Cooldown' => self::COOLDOWN,
                'MetricAggregationType' => 'Maximum',
                // Bounds are relative to the alarm threshold (80): ≥80 → +1, ≥90 → +2.
                // Saturation can't meaningfully clip (it's a %), so the deeper overshoot
                // gets the bigger step.
                'StepAdjustments' => [
                    ['MetricIntervalLowerBound' => 0, 'MetricIntervalUpperBound' => 10, 'ScalingAdjustment' => 1],
                    ['MetricIntervalLowerBound' => 10, 'ScalingAdjustment' => 2],
                ],
            ],
        ])['PolicyARN'];

        Aws::cloudWatch()->putMetricAlarm([
            'ActionsEnabled' => true,
            'AlarmName' => $this->alarmName(),
            'AlarmDescription' => 'Bursts the web service out when worker saturation spikes. Created by yolo CLI',
            'ComparisonOperator' => 'GreaterThanThreshold',
            'Dimensions' => [['Name' => self::METRIC_DIMENSION, 'Value' => self::serviceName()]],
            'EvaluationPeriods' => 1,
            'MetricName' => self::METRIC_NAME,
            'Namespace' => self::METRIC_NAMESPACE,
            'Period' => self::PERIOD,
            'Statistic' => 'Maximum',
            'Threshold' => self::ALARM_THRESHOLD,
            'TreatMissingData' => 'notBreaching',
            'AlarmActions' => [$policyArn],
            ...Aws::tags($this->tags()),
        ]);

        // PutMetricAlarm ignores Tags when updating an existing alarm, so reconcile
        // the ownership markers explicitly (TagResource works on an existing alarm) —
        // so the alarm reads as `ok` in yolo audit rather than rogue.
        Aws::synchroniseCloudWatchTags(
            CloudWatch::alarm($this->alarmName())['AlarmArn'],
            $this->tags(),
            apply: true,
        );

        return $changes;
    }

    /**
     * Tear the burst policy + alarm down — used when burst is switched off (or
     * autoscaling removed entirely). Deregistering the scalable target cascades the
     * step policy, but the self-authored alarm is standalone and must be deleted
     * explicitly, so this removes both and is safe to call when either is already
     * gone.
     *
     * @return array<int, Change>
     */
    public function teardown(bool $apply): array
    {
        $changes = [];

        if ($this->policyExists()) {
            $changes[] = Change::make('web burst policy', $this->policyName(), null);

            if ($apply) {
                ApplicationAutoScaling::deleteScalingPolicy(ScalableTarget::resourceId(), $this->policyName());
            }
        }

        if ($this->alarmExists()) {
            $changes[] = Change::make('web burst alarm', $this->alarmName(), null);

            if ($apply) {
                Aws::cloudWatch()->deleteAlarms(['AlarmNames' => [$this->alarmName()]]);
            }
        }

        return $changes;
    }

    public function policyExists(): bool
    {
        try {
            ApplicationAutoScaling::scalingPolicy(ScalableTarget::resourceId(), $this->policyName());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function alarmExists(): bool
    {
        try {
            CloudWatch::alarm($this->alarmName());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    /**
     * App-scoped ownership tags, matching what a Resource's ResolvesTags would
     * stamp. The yolo:environment baseline is added at write time by Aws::tags().
     *
     * @return array<string, string>
     */
    public function tags(): array
    {
        return [
            'Name' => $this->alarmName(),
            'yolo:scope' => Scope::App->value,
            'yolo:app' => Manifest::name(),
        ];
    }
}
