<?php

use Aws\Result;
use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\WaitReporter;

beforeEach(fn () => writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']));

afterEach(fn () => WaitReporter::clear());

it('completes once the waiter reaches its success state and ticks the reporter each poll', function () {
    $captured = [];

    bindMockElastiCacheClient([
        'DescribeReplicationGroups' => new Result(['ReplicationGroups' => [
            ['ReplicationGroupId' => 'yolo-testing-cache', 'Status' => 'available'],
        ]]),
    ], $captured);

    $ticks = 0;
    WaitReporter::using(function () use (&$ticks) {
        $ticks++;
    });

    Aws::waitFor(Aws::elastiCache(), 'ReplicationGroupAvailable', [
        'ReplicationGroupId' => 'yolo-testing-cache',
    ], timeout: 20 * 60);

    // The before-attempt callback fires at least once (before the first poll),
    // so the runner's heartbeat gets a chance to redraw even on a fast wait.
    expect($ticks)->toBeGreaterThanOrEqual(1);
    expect(array_column($captured, 'name'))->toContain('DescribeReplicationGroups');
});

it('honours the timeout by capping attempts (timeout/interval) rather than the SDK default', function () {
    $captured = [];

    // Never becomes available → the waiter must give up at our computed cap.
    bindMockElastiCacheClient([
        'DescribeReplicationGroups' => new Result(['ReplicationGroups' => [
            ['ReplicationGroupId' => 'yolo-testing-cache', 'Status' => 'creating'],
        ]]),
    ], $captured);

    // timeout 1s / interval 15s → ceil(1/15) = 1 attempt, so it fails fast with
    // no inter-attempt sleep instead of riding the SDK's 40-attempt default.
    expect(fn () => Aws::waitFor(Aws::elastiCache(), 'ReplicationGroupAvailable', [
        'ReplicationGroupId' => 'yolo-testing-cache',
    ], timeout: 1, interval: 15))->toThrow(RuntimeException::class);
});
