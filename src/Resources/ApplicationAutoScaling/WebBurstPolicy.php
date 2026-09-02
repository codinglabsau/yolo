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
 * on a saturation metric each web task emits itself — in-flight requests over the
 * Octane worker pool, or busy threads over the classic thread ceiling (requests queue
 * before latency climbs). Scale-out only — scale-in stays with target tracking, so this
 * can only add capacity faster, never fight them. Provisioned wherever web autoscaling
 * is, in either serving mode; not a knob.
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
     * Saturation quantises to busy/total, so a 4-worker task only reads 0/25/50/75/100 %;
     * with the strict `>` comparator, 70 trips at 3/4 yet stays under a larger pool's
     * higher steps. The worker count is auto-detected at runtime, not a manifest value,
     * so this is fixed across the realistic 4–16 range rather than derived.
     */
    public const int ALARM_THRESHOLD = 70;

    /**
     * The reporter publishes only at/above this, so the metric costs nothing when cold.
     * One quantised step (2/4 = 50 %) under the trip, so the alarm sees a not-breaching
     * datapoint first.
     */
    public const int EMIT_FLOOR = 50;

    /** 10s is the fast end of CloudWatch's high-resolution range. */
    private const int PERIOD = 10;

    /**
     * Also the reporter's window hold after a tripping datapoint: one breach already
     * steps the count out, so further datapoints would be ignored by the cooldown anyway.
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
            'Threshold' => self::ALARM_THRESHOLD,
            'Period' => self::PERIOD,
            'EvaluationPeriods' => 1,
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
     * Step bounds are relative to the alarm threshold: ≥70 → +1, ≥80 → +2, so a pinned
     * task (100 %) gets the bigger step.
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
