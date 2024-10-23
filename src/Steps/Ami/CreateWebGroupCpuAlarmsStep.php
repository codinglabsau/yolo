<?php

namespace Codinglabs\Yolo\Steps\Ami;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Illuminate\Support\Str;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\UsesAutoscaling;

class CreateWebGroupCpuAlarmsStep implements Step
{
    use UsesAutoscaling;

    public function __invoke(): StepResult
    {
        $alarmName = Helpers::keyedResourceName(sprintf('web-cpu-scaling-alarm-%s', Str::random(8)));
        $asgWeb = AwsResources::autoScalingGroupWeb();
        $snsTopic = AwsResources::topic(Manifest::get('aws.sns-topic'));
        $scaleUpPolicy = AwsResources::autoScalingGroupWebScaleUpPolicy();
        $scaleDownPolicy = AwsResources::autoScalingGroupWebScaleDownPolicy();

        Aws::cloudWatch()->putMetricAlarm([
            'ActionsEnabled' => true,
            'AlarmName' => $alarmName,
            'AlarmDescription' => 'Alarm if web CPU is too high. Created by yolo CLI',
            'ComparisonOperator' => 'GreaterThanThreshold',
            'Dimensions' => [
                [
                    'Name' => 'AutoScalingGroupName',
                    'Value' => $asgWeb['AutoScalingGroupName'],
                ],
            ],
            'EvaluationPeriods' => 1, // number of breached of Period before alarm
            'MetricName' => 'CPUUtilization',
            'Namespace' => 'AWS/EC2',
            'Period' => 60, // time to evaluate the metric
            'Statistic' => 'Average',
            'Threshold' => 60, // >= 60% average CPU
            'TreatMissingData' => 'notBreaching',
            'AlarmActions' => [
                $scaleUpPolicy['PolicyARN'],
            ],
            'OKActions' => [
                $scaleDownPolicy['PolicyARN'],
            ],
        ]);

        $alarmName = Helpers::keyedResourceName(sprintf('web-cpu-critical-alarm-%s', Str::random(8)));

        Aws::cloudWatch()->putMetricAlarm([
            'ActionsEnabled' => true,
            'AlarmName' => $alarmName,
            'AlarmDescription' => 'Alarm if web CPU is critical. Created by yolo CLI',
            'ComparisonOperator' => 'GreaterThanThreshold',
            'Dimensions' => [
                [
                    'Name' => 'AutoScalingGroupName',
                    'Value' => $asgWeb['AutoScalingGroupName'],
                ],
            ],
            'EvaluationPeriods' => 5, // number of breached of Period before alarm
            'MetricName' => 'CPUUtilization',
            'Namespace' => 'AWS/EC2',
            'Period' => 60, // time to evaluate the metric
            'Statistic' => 'Average',
            'Threshold' => 80, // >= 80% average CPU
            'TreatMissingData' => 'notBreaching',
            'AlarmActions' => [
                $snsTopic['TopicArn'],
            ],
            'OKActions' => [
                $snsTopic['TopicArn'],
            ],
        ]);

        return StepResult::SYNCED;
    }
}
