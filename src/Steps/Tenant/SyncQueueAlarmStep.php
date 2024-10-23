<?php

namespace Codinglabs\Yolo\Steps\Tenant;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Manifest;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\TenantStep;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncQueueAlarmStep extends TenantStep
{
    public function __invoke(array $options): StepResult
    {
        $alarmName = "{$this->tenantId()}-queue-depth-alarm";

        try {
            AwsResources::alarm($alarmName);
        } catch (ResourceDoesNotExistException) {
            // CloudWatch accepts an upsert operation, so we'll
            // always sync the alarm with the desired state.
        }

        $snsTopic = AwsResources::topic(Manifest::get('aws.sns-topic'));

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
                    'Value' => $this->tenantId(),
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
