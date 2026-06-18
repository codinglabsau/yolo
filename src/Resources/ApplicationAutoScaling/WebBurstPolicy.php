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
use Codinglabs\Yolo\Steps\Build\Fargate\GenerateSupervisorConfigStep;

/**
 * The **burst** scale-out path for the web service: a real-time companion to the
 * {@see WebConcurrencyPolicy} default, for the sudden spike the ~60s CloudWatch
 * metric floor can't catch in time. Not a knob — like the concurrency and CPU
 * policies it's just part of the scaling machinery, provisioned wherever web
 * autoscaling is. The signal is FrankenPHP's worker metrics, which only the worker
 * mode (Octane) populates — but it needs no gate: a classic-mode web tier simply
 * never emits the metric, so the alarm sits inert (INSUFFICIENT_DATA) and burst is
 * a no-op there. Nothing to switch on.
 *
 * The target-tracking policies scale on ALB metrics, which are 1-minute resolution
 * — a good signal, but ~1 min behind. This pairs a **step-scaling policy** with a
 * **high-resolution CloudWatch alarm** on a worker-saturation metric the container
 * emits itself: each web task reports its FrankenPHP busy/total worker ratio (an
 * *earlier* signal than the ALB — workers queue before latency even climbs) as a
 * high-res metric, only while it's hot. The alarm evaluates the most-saturated task
 * every 10s and steps the desired count out. Detection drops from ~60s to ~10–15s.
 *
 * The metric is published in real time via PutMetricData — the saturation emitter
 * ({@see GenerateSupervisorConfigStep}) reads
 * its own FrankenPHP worker gauges and puts a high-res datapoint, but only while it's
 * at or above the emit floor and only as often as a scale can act (it snoozes the
 * cooldown after a tripping datapoint — one breach already steps the count out). So
 * CloudWatch is touched only during a spike: near-zero cost, and the datapoint lands
 * synchronously — no riding the logs pipeline, whose flush cadence the ECS awslogs
 * driver won't let you tune (AWS recommends ≤5s for high-res EMF alarms) and whose
 * extraction is async, so an EMF datapoint surfaces on a cadence you don't control. The
 * task role carries a single namespace-scoped `cloudwatch:PutMetricData` grant ({@see
 * \Codinglabs\Yolo\Resources\Iam\EcsTaskPolicy}). FrankenPHP's metrics endpoint is
 * enabled by a Caddyfile YOLO generates (the app's Octane stub plus the top-level
 * `metrics` global option) and runs via `octane:start --caddyfile` — Octane
 * overwrites `CADDY_GLOBAL_OPTIONS`, so a task env var can't switch it on. Built only
 * for an autoscaling Octane web tier (see GenerateSupervisorConfigStep).
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
 * headroom land faster. And the in-container gauge is best-effort: a single
 * hard-pinned task (min 1, one small box at ~99% CPU) can starve the emitter along
 * with everything else, so no datapoint escapes and burst stays dark — the
 * target-tracking policies and `min ≥ 2` / more task CPU are the guarantees, burst
 * only sharpens the light-pin / multi-task case. Do not try to rescue that silence
 * by raising the emitter's scheduling priority above the web server: it scrapes
 * web's own metrics endpoint, so outranking web starves the scrape (the regression
 * this replaced). See {@see GenerateSupervisorConfigStep} for the priority ordering.
 */
class WebBurstPolicy
{
    /** Namespace + metric the saturation emitter publishes and this alarm reads — the contract between them. */
    public const string METRIC_NAMESPACE = 'YOLO/Autoscaling';

    public const string METRIC_NAME = 'WorkerSaturation';

    public const string METRIC_DIMENSION = 'ServiceName';

    /**
     * Worker-saturation % at which the alarm trips and the burst steps out. Set so a
     * small worker pool can actually reach it: saturation quantises to busy/total, so a
     * 4-worker task only ever reads 0/25/50/75/100 % — a threshold of 80 (the old value)
     * needed a sustained 4/4 = 100 %, which a real pool rarely holds, so burst never
     * tripped. With the strict `>` comparator, 70 trips at 3/4 = 75 % yet stays under a
     * larger pool's higher steps. yolo doesn't know the app's real FrankenPHP worker
     * count (it's auto-detected at runtime, not a manifest value), so this is a fixed
     * default that holds across the realistic 4–16 worker range rather than a derived one.
     */
    public const int ALARM_THRESHOLD = 70;

    /**
     * The emitter only publishes at or above this saturation %, so the metric (and its
     * cost) is near-zero when the service isn't hot. Below the alarm threshold so the
     * alarm is fed a not-breaching datapoint on the step just under the trip — for a
     * 4-worker pool that's the 50 % (2/4) reading, so the floor sits at 50.
     */
    public const int EMIT_FLOOR = 50;

    /** High-resolution alarm: a 10s period is the fast end of CloudWatch's range. */
    private const int PERIOD = 10;

    /**
     * Step-scaling cooldown — also the emitter's snooze after a tripping datapoint:
     * one breach already steps the desired count out, so it pauses for that scale to
     * land rather than putting more datapoints the cooldown would ignore anyway.
     */
    public const int COOLDOWN = 60;

    /**
     * The emitter's poll cadence — how often it reads its local metrics endpoint when
     * idle, and the snooze between hot-but-not-tripping puts. A cheap localhost read;
     * the only AWS call is the put, and only when saturation is at/over the floor.
     */
    public const int POLL_INTERVAL = 5;

    public function policyName(): string
    {
        return Helpers::keyedResourceName('web-burst-policy');
    }

    public function alarmName(): string
    {
        return Helpers::keyedResourceName('web-worker-saturation');
    }

    /** The web service name the metric is dimensioned by — baked into the emitter too. */
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
                // Bounds are relative to the alarm threshold (70): ≥70 → +1, ≥80 → +2.
                // Saturation can't meaningfully clip (it's a %), so the deeper overshoot
                // gets the bigger step — a 4-worker task reads 75 → +1, a pinned 100 → +2.
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
