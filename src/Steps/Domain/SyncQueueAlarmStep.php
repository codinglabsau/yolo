<?php

namespace Codinglabs\Yolo\Steps\Domain;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesDomainStep;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncQueueAlarmStep implements ExecutesDomainStep
{
    public function __invoke(array $options): StepResult
    {
        $alarmName = Helpers::keyedResourceName("queue-depth-alarm");

        try {
            AwsResources::alarm($alarmName);
        } catch (ResourceDoesNotExistException) {
            // CloudWatch accepts an upsert operation, so we'll
            // always sync the alarm with the desired state.
        }

        $snsTopic = AwsResources::topic();

        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_SYNC;
        }

        Aws::cloudWatch()->putMetricAlarm([
            'ActionsEnabled' => true,
            'AlarmName' => $alarmName,
            'AlarmDescription' => 'Alarm if queue is too deep. Created by yolo CLI',
            'ComparisonOperator' => 'GreaterThanThreshold',
            'Dimensions' => [
                [
                    'Name' => 'QueueName',
                    'Value' => Helpers::keyedResourceName(),
                ],
            ],
            'EvaluationPeriods' => 3, // number of breached of Period before alarm
            'MetricName' => 'ApproximateNumberOfMessagesVisible',
            'Namespace' => 'AWS/SQS',
            'Period' => 300, // time to evaluate the metric
            'Statistic' => 'Sum',
            'Threshold' => 100,
            'TreatMissingData' => 'notBreaching',
            'AlarmActions' => [$snsTopic['TopicArn']],
            'OKActions' => [$snsTopic['TopicArn']],
        ]);

        return StepResult::SYNCED;
    }
}
