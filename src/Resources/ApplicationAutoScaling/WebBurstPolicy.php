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
use Codinglabs\Yolo\Resources\Iam\EcsTaskPolicy;
use Codinglabs\Yolo\Runtime\WorkerSaturationReporter;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Real-time burst scale-out beside {@see WebConcurrencyPolicy}: target tracking rides
 * 1-minute ALB metrics, so this pairs a step-scaling policy with a 10s high-res alarm
 * on a saturation metric each web task emits itself — busy workers over the Octane
 * worker pool, or busy threads over the classic thread ceiling, queued requests counted
 * in both (requests queue before latency climbs). The alarm line is per tier
 * ({@see alarmThreshold()}); the alarm shape is shared. Scale-out only — scale-in stays
 * with target tracking, so this can only add capacity faster, never fight them.
 * Provisioned wherever web autoscaling is, in either serving mode; not a knob.
 *
 * {@see WorkerSaturationReporter} publishes synchronously via PutMetricData from an
 * after-response hook, only while hot (grant in {@see EcsTaskPolicy}). Not EMF via
 * logs: the awslogs driver's flush cadence isn't tunable and extraction is async, so an
 * EMF datapoint would surface on a cadence we don't control. FrankenPHP's metrics
 * endpoint is enabled by a YOLO-generated Caddyfile — Octane overwrites
 * `CADDY_GLOBAL_OPTIONS`, so a task env var can't switch it on.
 *
 * Burst complements warm capacity, never replaces it: a new task still needs ~55s to
 * boot and pass ALB health. The in-request publish is best-effort — a hard-pinned task
 * where no request completes goes dark — so target tracking and `min ≥ 2` remain the
 * guarantees.
 */
class WebBurstPolicy
{
    /** The contract between the runtime reporter and this alarm. */
    public const string METRIC_NAMESPACE = 'YOLO/Autoscaling';

    public const string METRIC_NAME = 'WorkerSaturation';

    public const string METRIC_DIMENSION = 'ServiceName';

    /**
     * Classic mode: the numerator carries queue depth and the thread ceiling is large
     * (32/vCPU), so a fractional reading below a full pin is real load — 70 trips one
     * step below it while staying under a small ceiling's coarse quantisation (a
     * 4-thread task reads only 0/25/50/75/100, and 75 must clear the strict `>`).
     */
    public const int CLASSIC_ALARM_THRESHOLD = 70;

    /**
     * Octane: the `busy_workers` gauge counts every dispatched request, health checks
     * included, and a small resident pool (16/vCPU) quantises coarsely — two or three
     * load-balancer probes landing in one window read 6-9 of 8 on an idle task, which
     * a fraction-of-pool line mistakes for a burst. Probes can never queue a pool, so
     * the honest cut is "dispatched exceeds the pool": strictly over 100 means requests
     * are waiting for a worker. The same absolute count on a 32-worker pool reads under
     * 20 either way; only the small pool ever saw the false trip.
     */
    public const int OCTANE_ALARM_THRESHOLD = 100;

    /**
     * The reporter publishes only at/above this, so the metric costs nothing when cold.
     * Under both thresholds, so the alarm sees a not-breaching datapoint first.
     */
    public const int EMIT_FLOOR = 50;

    /** 10s is the fast end of CloudWatch's high-resolution range. */
    private const int PERIOD = 10;

    /**
     * Two consecutive breaching periods, so one window in which probes and requests
     * coincide can't buy a task. Detection is therefore two periods plus the reporter's
     * {@see POLL_INTERVAL} debounce: 20-25s from a spike to ALARM. A real spike clears
     * that easily — the gauge climbs from tens to hundreds inside a single window.
     */
    private const int EVALUATION_PERIODS = 2;

    /**
     * The step policy's cooldown. Step scaling keeps counting capacity a previous step
     * added toward the next evaluation for this long, so an alarm that stays in ALARM
     * at the same band adds nothing more — the reporter needs no hold of its own.
     */
    public const int COOLDOWN = 60;

    /** Reporter debounce: at most one scrape + put per this many seconds per task. */
    public const int POLL_INTERVAL = 5;

    public function policyName(): string
    {
        return Helpers::keyedResourceName('web-burst-policy');
    }

    public function alarmName(): string
    {
        return Helpers::keyedResourceName('web-worker-saturation');
    }

    public static function serviceName(): string
    {
        return (new EcsService(ServerGroup::WEB))->name();
    }

    /**
     * The alarm line for a serving mode, with the strict `>` comparator. The one place
     * the tier picks a threshold: the alarm, the reporter's SSR-shed gate and the
     * dashboard annotation all read it, so no consumer can carry the other tier's line.
     */
    public static function alarmThreshold(bool $octane): int
    {
        return $octane ? self::OCTANE_ALARM_THRESHOLD : self::CLASSIC_ALARM_THRESHOLD;
    }

    public function exists(): bool
    {
        return $this->policyExists() && $this->alarmExists();
    }

    /**
     * Drift is "missing" OR "owned config differs": the threshold and step config are
     * code constants that change between yolo versions and an existing alarm is never
     * recreated, so an existence-only check would never push a new value to a
     * provisioned environment. Changes are built regardless of $apply so the plan and
     * apply passes agree.
     *
     * @return array<int, Change>
     */
    public function synchronise(bool $apply): array
    {
        $livePolicy = $this->livePolicy();
        $liveAlarm = $this->liveAlarm();

        $policyChanges = $livePolicy === null
            ? [Change::make('web burst policy', null, $this->policyName())]
            : $this->policyDrift($livePolicy);

        $alarmChanges = $liveAlarm === null
            ? [Change::make('web burst alarm', null, $this->alarmName())]
            : $this->alarmDrift($liveAlarm);

        $changes = [...$policyChanges, ...$alarmChanges];

        if ($changes === [] || ! $apply) {
            return $changes;
        }

        // The policy ARN is stable per name, so an alarm-only drift needn't re-put the policy.
        $policyArn = $policyChanges === []
            ? $livePolicy['PolicyARN']
            : Aws::applicationAutoScaling()->putScalingPolicy($this->policyDefinition())['PolicyARN'];

        if ($alarmChanges !== []) {
            Aws::cloudWatch()->putMetricAlarm($this->alarmDefinition($policyArn));

            // PutMetricAlarm ignores Tags on an existing alarm; without this audit reads it as rogue.
            Aws::synchroniseCloudWatchTags(
                CloudWatch::alarm($this->alarmName())['AlarmArn'],
                $this->tags(),
                apply: true,
            );
        }

        return $changes;
    }

    /**
     * Per-field Changes so the plan reads "Threshold: 80 → 70". CloudWatch echoes
     * numerics back as floats, so those compare by value.
     *
     * @param  array<string, mixed>  $live
     * @return array<int, Change>
     */
    private function alarmDrift(array $live): array
    {
        $changes = [];

        foreach ($this->alarmBehaviour() as $key => $desired) {
            $current = $live[$key] ?? null;

            $matches = is_int($desired)
                ? $current !== null && (float) $current === (float) $desired
                : $current === $desired;

            if (! $matches) {
                $changes[] = Change::make("web burst alarm {$key}", $current, $desired);
            }
        }

        return $changes;
    }

    /**
     * Compared as one normalised unit: AWS returns step bounds as floats and omits an
     * absent upper bound.
     *
     * @param  array<string, mixed>  $live
     * @return array<int, Change>
     */
    private function policyDrift(array $live): array
    {
        $desired = $this->normalisePolicyConfig($this->policyDefinition()['StepScalingPolicyConfiguration']);
        $current = $this->normalisePolicyConfig($live['StepScalingPolicyConfiguration'] ?? []);

        if ($current === $desired) {
            return [];
        }

        return [Change::make('web burst policy config', $current, $desired)];
    }

    /**
     * Shared by {@see alarmDefinition()} and {@see alarmDrift()} so write and drift agree.
     *
     * @return array<string, int|string>
     */
    private function alarmBehaviour(): array
    {
        return [
            'Threshold' => self::alarmThreshold(Manifest::usesOctane()),
            'Period' => self::PERIOD,
            'EvaluationPeriods' => self::EVALUATION_PERIODS,
            'DatapointsToAlarm' => self::EVALUATION_PERIODS,
            'ComparisonOperator' => 'GreaterThanThreshold',
            'Statistic' => 'Maximum',
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function normalisePolicyConfig(array $config): array
    {
        return [
            'AdjustmentType' => $config['AdjustmentType'] ?? null,
            'Cooldown' => isset($config['Cooldown']) ? (int) $config['Cooldown'] : null,
            'MetricAggregationType' => $config['MetricAggregationType'] ?? null,
            'StepAdjustments' => array_map(fn (array $step): array => [
                'lower' => isset($step['MetricIntervalLowerBound']) ? (float) $step['MetricIntervalLowerBound'] : null,
                'upper' => isset($step['MetricIntervalUpperBound']) ? (float) $step['MetricIntervalUpperBound'] : null,
                'adjustment' => (int) $step['ScalingAdjustment'],
            ], $config['StepAdjustments'] ?? []),
        ];
    }

    /**
     * Step bounds are relative to the alarm threshold: up to 10 over → +1, beyond → +2.
     * Classic: 70-80 adds one, a pinned task (100) gets two. Octane: one queued request
     * on a pool of ten or fewer already quantises past the +1 band (9 of 8 is 112.5), so
     * a small pool goes straight to +2 — accepted, since a real queue is real demand and
     * the threshold change only removed the false positives.
     *
     * @return array<string, mixed>
     */
    private function policyDefinition(): array
    {
        return [
            'PolicyName' => $this->policyName(),
            'ServiceNamespace' => ApplicationAutoScaling::SERVICE_NAMESPACE,
            'ResourceId' => ScalableTarget::resourceId(),
            'ScalableDimension' => ApplicationAutoScaling::SCALABLE_DIMENSION,
            'PolicyType' => 'StepScaling',
            'StepScalingPolicyConfiguration' => [
                'AdjustmentType' => 'ChangeInCapacity',
                'Cooldown' => self::COOLDOWN,
                'MetricAggregationType' => 'Maximum',
                'StepAdjustments' => [
                    ['MetricIntervalLowerBound' => 0, 'MetricIntervalUpperBound' => 10, 'ScalingAdjustment' => 1],
                    ['MetricIntervalLowerBound' => 10, 'ScalingAdjustment' => 2],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function alarmDefinition(string $policyArn): array
    {
        return [
            'ActionsEnabled' => true,
            'AlarmName' => $this->alarmName(),
            'AlarmDescription' => 'Bursts the web service out when worker saturation spikes. Created by yolo CLI',
            'Dimensions' => [['Name' => self::METRIC_DIMENSION, 'Value' => self::serviceName()]],
            'MetricName' => self::METRIC_NAME,
            'Namespace' => self::METRIC_NAMESPACE,
            'TreatMissingData' => 'notBreaching',
            'AlarmActions' => [$policyArn],
            ...$this->alarmBehaviour(),
            ...Aws::tags($this->tags()),
        ];
    }

    /**
     * Deregistering the scalable target cascades the policy, but the alarm is standalone
     * and must be deleted explicitly.
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
        return $this->livePolicy() !== null;
    }

    public function alarmExists(): bool
    {
        return $this->liveAlarm() !== null;
    }

    /**
     * Never throws — the plan pass runs before anything exists.
     *
     * @return array<string, mixed>|null
     */
    private function livePolicy(): ?array
    {
        try {
            return ApplicationAutoScaling::scalingPolicy(ScalableTarget::resourceId(), $this->policyName());
        } catch (ResourceDoesNotExistException) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function liveAlarm(): ?array
    {
        try {
            return CloudWatch::alarm($this->alarmName());
        } catch (ResourceDoesNotExistException) {
            return null;
        }
    }

    /**
     * Mirrors ResolvesTags; yolo:environment is added at write time by Aws::tags().
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
