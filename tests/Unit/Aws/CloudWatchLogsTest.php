<?php

use Aws\Result;
use Codinglabs\Yolo\Helpers;
use Aws\Command as AwsCommand;
use GuzzleHttp\Promise\Create;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Aws\CloudWatchLogs;
use GuzzleHttp\Promise\PromiseInterface;
use Aws\CloudWatchLogs\CloudWatchLogsClient;

function cloudWatchLogsMock(Result|AwsException $entry): CloudWatchLogsClient
{
    return new CloudWatchLogsClient([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => fn ($cmd, $req): PromiseInterface => $entry instanceof AwsException
            ? Create::rejectionFor($entry)
            : Create::promiseFor($entry),
    ]);
}

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
