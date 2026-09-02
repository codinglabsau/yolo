<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Services\Alerts;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;
use Codinglabs\Yolo\Resources\ElbV2\TargetGroup;
use Codinglabs\Yolo\Resources\ElbV2\LoadBalancer;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\CloudWatch\Dashboard;
use Codinglabs\Yolo\Resources\CloudWatch\AlertAlarm;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * A rate, not a count, so a busy app and a quiet one share one threshold —
 * with a traffic floor so a single error on a trickle of requests can't page.
 * The dimensions are ARN suffixes, so on a greenfield plan the alarm reports pending.
 */
class SyncWebAlertAlarmStep implements ExecutesWebStep
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        try {
            $dimensions = [
                ['Name' => 'TargetGroup', 'Value' => Dashboard::targetGroupDimension((new TargetGroup())->arn())],
                ['Name' => 'LoadBalancer', 'Value' => Dashboard::loadBalancerDimension((new LoadBalancer())->arn())],
            ];
        } catch (ResourceDoesNotExistException) {
            $this->recordChange(Change::make('web 5xx alert', null, 'created (target group pending)'));

            return StepResult::WOULD_CREATE;
        }

        return $this->syncResource($this->alarm($dimensions), $options);
    }

    /**
     * @param  array<int, array{Name: string, Value: string}>  $dimensions
     */
    protected function alarm(array $dimensions): AlertAlarm
    {
        return new AlertAlarm(
            suffix: 'web-5xx',
            description: 'This app is serving 5xx to at least 5% of its requests - users are seeing errors',
            alarmScope: Scope::App,
            comparisonOperator: 'GreaterThanOrEqualToThreshold',
            threshold: Alerts::WEB_5XX_RATE_PERCENT,
            evaluationPeriods: 5,
            datapointsToAlarm: 3,
            metrics: [
                [
                    'Id' => 'requests',
                    'MetricStat' => [
                        'Metric' => [
                            'Namespace' => 'AWS/ApplicationELB',
                            'MetricName' => 'RequestCount',
                            'Dimensions' => $dimensions,
                        ],
                        'Period' => 60,
                        'Stat' => 'Sum',
                    ],
                    'ReturnData' => false,
                ],
                [
                    'Id' => 'errors',
                    'MetricStat' => [
                        'Metric' => [
                            'Namespace' => 'AWS/ApplicationELB',
                            'MetricName' => 'HTTPCode_Target_5XX_Count',
                            'Dimensions' => $dimensions,
                        ],
                        'Period' => 60,
                        'Stat' => 'Sum',
                    ],
                    'ReturnData' => false,
                ],
                [
                    'Id' => 'rate',
                    // Under 60 requests/min the rate pins to 0 so one error on three
                    // requests can't page; FILL backstops the 5xx series, which has
                    // no datapoints in an error-free minute.
                    'Expression' => 'IF(requests >= 60, 100 * FILL(errors, 0) / requests, 0)',
                    'Label' => 'target 5xx rate %',
                    'ReturnData' => true,
                ],
            ],
        );
    }
}
