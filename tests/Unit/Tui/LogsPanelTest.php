<?php

use Aws\Result;
use Codinglabs\Yolo\Helpers;
use Aws\Command as AwsCommand;
use GuzzleHttp\Promise\Create;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Aws\CloudWatchLogs;
use Codinglabs\Yolo\Tui\Panels\LogsPanel;
use Aws\CloudWatchLogs\CloudWatchLogsClient;

function cloudWatchLogsMock(Result|AwsException $entry): CloudWatchLogsClient
{
    return new CloudWatchLogsClient([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => fn ($cmd, $req) => $entry instanceof AwsException
            ? Create::rejectionFor($entry)
            : Create::promiseFor($entry),
    ]);
}

it('formats log events as timestamped lines, oldest first', function (): void {
    $lines = LogsPanel::eventLines([
        ['timestamp' => 1718000000000, 'message' => 'booting'],
        ['timestamp' => 1718000001000, 'message' => 'ready'],
    ]);

    expect($lines)->toHaveCount(2)
        ->and(implode("\n", $lines))->toContain('booting')->toContain('ready');
});

it('shows an empty state when there are no log events', function (): void {
    expect(implode("\n", LogsPanel::eventLines([])))->toContain('No recent log events');
});

it('reads recent events from CloudWatch', function (): void {
    Helpers::app()->instance('cloudWatchLogs', cloudWatchLogsMock(
        new Result(['events' => [['timestamp' => 1718000000000, 'message' => 'hi']]]),
    ));

    expect(CloudWatchLogs::recent('/yolo/testing/app', 'web'))->toHaveCount(1);
});

it('treats a missing log group as no events', function (): void {
    Helpers::app()->instance('cloudWatchLogs', cloudWatchLogsMock(
        new AwsException('missing', new AwsCommand('FilterLogEvents'), ['code' => 'ResourceNotFoundException']),
    ));

    expect(CloudWatchLogs::recent('/yolo/testing/app', 'web'))->toBe([]);
});
