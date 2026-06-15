<?php

declare(strict_types=1);

use Aws\Result;
use Aws\Command;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Aws\CloudWatch;

it('returns a metric series ordered oldest to newest, dropping empty periods', function (): void {
    $captured = [];
    bindMockCloudWatchClient([
        'GetMetricStatistics' => new Result(['Datapoints' => [
            ['Timestamp' => 1000, 'Average' => 30.0],
            ['Timestamp' => 940, 'Average' => 10.0],
            ['Timestamp' => 970, 'Average' => 20.0],
            ['Timestamp' => 1030, 'Unit' => 'Percent'], // no Average for this period → dropped
        ]]),
    ], $captured);

    $series = CloudWatch::metricSeries('AWS/ECS', 'CPUUtilization', [['Name' => 'ClusterName', 'Value' => 'c']], 'Average');

    expect($series)->toBe([10.0, 20.0, 30.0]);

    // Shape of the request we sent (MockHandler proves request shape only).
    expect($captured[0]['name'])->toBe('GetMetricStatistics');
    expect($captured[0]['args'])->toMatchArray([
        'Namespace' => 'AWS/ECS',
        'MetricName' => 'CPUUtilization',
        'Period' => 60,
        'Statistics' => ['Average'],
    ]);
});

it('returns an empty series when the metric read fails', function (): void {
    $captured = [];
    bindMockCloudWatchClient([
        'GetMetricStatistics' => new AwsException('boom', new Command('GetMetricStatistics')),
    ], $captured);

    expect(CloudWatch::metricSeries('AWS/ECS', 'CPUUtilization', [], 'Average'))->toBe([]);
});

it('returns an empty series when there are no datapoints in the window', function (): void {
    $captured = [];
    bindMockCloudWatchClient(['GetMetricStatistics' => new Result(['Datapoints' => []])], $captured);

    expect(CloudWatch::metricSeries('AWS/ApplicationELB', 'TargetResponseTime', [], 'Average'))->toBe([]);
});
