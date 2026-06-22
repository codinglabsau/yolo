<?php

namespace Codinglabs\Yolo\Resources\CloudWatch;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Aws\CloudWatch;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\Sns\SnsAlarmTopic;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * A CloudWatch alarm that fires to the SNS alarm topic when an SQS queue gets
 * too deep, addressed by alarm + queue name so the solo, tenant and landlord
 * steps share it.
 *
 * A full Resource (+ SynchronisesConfiguration) so it rides the same create-or-sync
 * path as every other resource, with its tag AND config drift surfacing
 * symmetrically through syncResource(): a clean alarm records no change and is
 * pruned before apply; a drifted one is listed current → desired.
 *
 * putMetricAlarm has no create/exists split (it's a pure upsert), which exists()
 * = DescribeAlarms + create() = putMetricAlarm resolves cleanly. Tags need their
 * own reconcile because PutMetricAlarm only applies its Tags field when it
 * *creates* an alarm and silently ignores it on later updates — TagResource works
 * on an existing alarm, so synchroniseTags() back-fills any alarm first put
 * untagged.
 */
class QueueAlarm implements Deletable, Resource, SynchronisesConfiguration
{
    use ResolvesTags;

    public function __construct(
        protected string $alarmName,
        protected string $queueName,
        protected string $statistic = 'Average',
    ) {}

    public function name(): string
    {
        return $this->alarmName;
    }

    public function scope(): Scope
    {
        return Scope::App;
    }

    public function exists(): bool
    {
        try {
            CloudWatch::alarm($this->alarmName);

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return CloudWatch::alarm($this->alarmName)['AlarmArn'];
    }

    /** Delete the alarm; deleteAlarms is idempotent on a missing alarm. */
    public function delete(): void
    {
        Aws::cloudWatch()->deleteAlarms([
            'AlarmNames' => [$this->name()],
        ]);
    }

    public function create(): void
    {
        $this->putMetricAlarm();
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseCloudWatchTags($this->arn(), $this->tags(), $apply);
    }

    /**
     * Diff the live alarm's reconciled attributes against the desired ones and,
     * when any drift, re-put the whole desired alarm (putMetricAlarm is an upsert).
     * Returns the drifted attributes as Change[] so the plan surfaces them and the
     * apply pass isn't dropped by the only-pending-steps filter — empty when clean.
     *
     * @return array<int, Change>
     */
    public function synchroniseConfiguration(bool $apply = true): array
    {
        $changes = $this->configurationChanges(CloudWatch::alarm($this->alarmName));

        if ($changes !== [] && $apply) {
            $this->putMetricAlarm();
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $live
     * @return array<int, Change>
     */
    protected function configurationChanges(array $live): array
    {
        $topicArn = (new SnsAlarmTopic())->arn();

        $attributes = [
            // ActionsEnabled is reconciled so an alarm toggled off in the console heals
            // back on; the alarm's metric/namespace/dimensions are hardcoded constants
            // keyed to the queue, so they can't drift on a YOLO-managed alarm.
            'actions-enabled' => [true, $live['ActionsEnabled'] ?? null],
            'comparison-operator' => ['GreaterThanThreshold', $live['ComparisonOperator'] ?? null],
            'evaluation-periods' => [(int) Manifest::get('sqs.depth-alarm-evaluation-periods', 3), isset($live['EvaluationPeriods']) ? (int) $live['EvaluationPeriods'] : null],
            'period' => [(int) Manifest::get('sqs.depth-alarm-period', 300), isset($live['Period']) ? (int) $live['Period'] : null],
            'statistic' => [$this->statistic, $live['Statistic'] ?? null],
            'threshold' => [(float) Manifest::get('sqs.depth-alarm-threshold', 100), isset($live['Threshold']) ? (float) $live['Threshold'] : null],
            'treat-missing-data' => ['notBreaching', $live['TreatMissingData'] ?? null],
            'alarm-actions' => [[$topicArn], $live['AlarmActions'] ?? []],
            'ok-actions' => [[$topicArn], $live['OKActions'] ?? []],
        ];

        $changes = [];

        foreach ($attributes as $label => [$desired, $current]) {
            if ($desired !== $current) {
                $changes[] = Change::make($label, $current, $desired);
            }
        }

        return $changes;
    }

    protected function putMetricAlarm(): void
    {
        $topicArn = (new SnsAlarmTopic())->arn();

        Aws::cloudWatch()->putMetricAlarm([
            'ActionsEnabled' => true,
            'AlarmName' => $this->alarmName,
            'AlarmDescription' => 'Alarm if queue is too deep. Created by yolo CLI',
            'ComparisonOperator' => 'GreaterThanThreshold',
            'Dimensions' => [
                ['Name' => 'QueueName', 'Value' => $this->queueName],
            ],
            'EvaluationPeriods' => Manifest::get('sqs.depth-alarm-evaluation-periods', 3),
            'MetricName' => 'ApproximateNumberOfMessagesVisible',
            'Namespace' => 'AWS/SQS',
            'Period' => Manifest::get('sqs.depth-alarm-period', 300),
            'Statistic' => $this->statistic,
            'Threshold' => Manifest::get('sqs.depth-alarm-threshold', 100),
            'TreatMissingData' => 'notBreaching',
            'AlarmActions' => [$topicArn],
            'OKActions' => [$topicArn],
            ...Aws::tags($this->tags()),
        ]);
    }
}
