<?php

namespace Codinglabs\Yolo\Resources\CloudWatch;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Resources\Sns\SnsAlarmTopic;

/**
 * A CloudWatch alarm that fires to the SNS alarm topic when an SQS queue gets
 * too deep, addressed by alarm + queue name so the solo, tenant and landlord
 * steps share it. putMetricAlarm is a pure upsert (no create/exists split), so
 * this doesn't implement the Resource contract — it just reconciles the desired
 * alarm on every sync.
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
            ...Aws::tags(),
        ]);
    }
}
