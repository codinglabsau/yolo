<?php

namespace Codinglabs\Yolo\Resources\CloudWatch;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Aws\CloudWatch;
use Codinglabs\Yolo\Resources\Sns\SnsAlarmTopic;

/**
 * A CloudWatch alarm that fires to the SNS alarm topic when an SQS queue gets
 * too deep, addressed by alarm + queue name so the solo, tenant and landlord
 * steps share it. putMetricAlarm is a pure upsert (no create/exists split), so
 * this doesn't implement the Resource contract — it just reconciles the desired
 * alarm on every sync.
 *
 * Tags need their own reconcile: PutMetricAlarm only applies its Tags field when
 * it *creates* an alarm and silently ignores it on every later update, so an
 * alarm that was first put untagged (e.g. before tagging existed) would never
 * pick the tags up. TagResource works on an existing alarm, so after the upsert
 * we reconcile the app-scoped ownership tags onto it — the same yolo:scope /
 * yolo:app markers a Resource carries, so the alarm reads as `ok` in yolo audit
 * instead of `rogue`.
 */
class QueueAlarm
{
    public function __construct(
        protected string $alarmName,
        protected string $queueName,
        protected string $statistic = 'Average',
    ) {}

    public function synchronise(): void
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
            'EvaluationPeriods' => Manifest::get('aws.sqs.depth-alarm-evaluation-periods', 3),
            'MetricName' => 'ApproximateNumberOfMessagesVisible',
            'Namespace' => 'AWS/SQS',
            'Period' => Manifest::get('aws.sqs.depth-alarm-period', 300),
            'Statistic' => $this->statistic,
            'Threshold' => Manifest::get('aws.sqs.depth-alarm-threshold', 100),
            'TreatMissingData' => 'notBreaching',
            'AlarmActions' => [$topicArn],
            'OKActions' => [$topicArn],
            ...Aws::tags($this->tags()),
        ]);

        // PutMetricAlarm ignores Tags when updating an existing alarm, so reconcile
        // them explicitly (TagResource does work on an existing alarm) — this also
        // back-fills any alarm that was first created untagged.
        Aws::synchroniseCloudWatchTags(
            CloudWatch::alarm($this->alarmName)['AlarmArn'],
            $this->tags(),
            apply: true,
        );
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
            'Name' => $this->alarmName,
            'yolo:scope' => Scope::App->value,
            'yolo:app' => Manifest::name(),
        ];
    }
}
