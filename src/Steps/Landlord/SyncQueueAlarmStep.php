<?php

namespace Codinglabs\Yolo\Steps\Landlord;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesMultitenancyStep;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncQueueAlarmStep implements ExecutesMultitenancyStep
{
    public function __invoke(array $options): StepResult
    {
        $alarmName = Helpers::keyedResourceName('landlord-queue-depth-alarm');

        try {
            AwsResources::alarm($alarmName);
        } catch (ResourceDoesNotExistException) {
            // CloudWatch accepts an upsert operation, so we'll
            // always sync the alarm with the desired state.
        }

        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_SYNC;
        }

        $snsTopic = AwsResources::alarmTopic();

        Aws::cloudWatch()->putMetricAlarm([
            'ActionsEnabled' => true,
            'AlarmName' => $alarmName,
            'AlarmDescription' => 'Alarm if queue is too deep. Created by yolo CLI',
            'ComparisonOperator' => 'GreaterThanThreshold',
            'Dimensions' => [
                [
                    'Name' => 'QueueName',
                    'Value' => Helpers::keyedResourceName('landlord'),
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
            ...Aws::tags(),
        ]);

        return StepResult::SYNCED;
    }
}
