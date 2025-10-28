<?php

namespace Codinglabs\Yolo\Steps\Tenant;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\TenantStep;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncQueueAlarmStep extends TenantStep
{
    public function __invoke(array $options): StepResult
    {
        $alarmName = Helpers::keyedResourceName(sprintf('%s-queue-depth-alarm', $this->tenantId()));

        try {
            AwsResources::alarm($alarmName);
        } catch (ResourceDoesNotExistException) {
            // CloudWatch accepts an upsert operation, so we'll
            // always sync the alarm with the desired state.
        }

        $snsTopic = AwsResources::alarmTopic();

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
                    'Value' => Helpers::keyedResourceName($this->tenantId()),
                ],
            ],
            'EvaluationPeriods' => Manifest::get('aws.queue.evaluation-periods', 3), // number of breaches of the Period before alarm
            'MetricName' => 'ApproximateNumberOfMessagesVisible',
            'Namespace' => 'AWS/SQS',
            'Period' => Manifest::get('aws.queue.period', 300), // time to evaluate the metric
            'Statistic' => 'Average',
            'Threshold' => Manifest::get('aws.queue.threshold', 100),
            'TreatMissingData' => 'notBreaching',
            'AlarmActions' => [$snsTopic['TopicArn']],
            'OKActions' => [$snsTopic['TopicArn']],
            ...Aws::tags(),
        ]);

        return StepResult::SYNCED;
    }
}
