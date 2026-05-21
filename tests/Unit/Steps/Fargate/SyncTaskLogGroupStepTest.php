<?php

use Aws\Result;
use Aws\MockHandler;
use Aws\CommandInterface;
use Codinglabs\Yolo\Helpers;
use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Codinglabs\Yolo\Steps\Fargate\SyncTaskLogGroupStep;

/**
 * Bind a mock CloudWatch Logs client. Captured calls are written into $captured
 * so the test can inspect arg shapes.
 *
 * @param  array<int, Result>  $results  responses to return in order
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured  filled by reference
 */
function bindMockCloudWatchLogsClient(array $results, array &$captured): void
{
    $mock = new MockHandler();

    foreach ($results as $result) {
        $mock->append(function (CommandInterface $cmd) use ($result, &$captured) {
            $captured[] = ['name' => $cmd->getName(), 'args' => $cmd->toArray()];

            return $result;
        });
    }

    Helpers::app()->instance('cloudWatchLogs', new CloudWatchLogsClient([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

beforeEach(function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'tasks' => ['web' => ['log-group' => '/yolo/my-app']],
    ]);
});

it('strips the stream wildcard `:*` suffix before calling the CloudWatch Logs tag APIs', function () {
    $captured = [];

    bindMockCloudWatchLogsClient([
        // describeLogGroups — returns the log group with the wildcard-suffixed `arn`
        // that AWS actually returns for log groups.
        new Result([
            'logGroups' => [[
                'logGroupName' => '/yolo/my-app',
                'arn' => 'arn:aws:logs:ap-southeast-2:111111111111:log-group:/yolo/my-app:*',
                'retentionInDays' => 30,
            ]],
        ]),
        // listTagsForResource — return empty so reconcileTags decides no writes needed.
        new Result(['tags' => []]),
        // tagResource — return so reconcileTags can apply the Name tag.
        new Result([]),
    ], $captured);

    (new SyncTaskLogGroupStep())([]);

    $tagCalls = array_values(array_filter(
        $captured,
        fn (array $call) => in_array($call['name'], ['ListTagsForResource', 'TagResource'], true),
    ));

    expect($tagCalls)->not->toBeEmpty();

    foreach ($tagCalls as $call) {
        expect($call['args']['resourceArn'])
            ->toBe('arn:aws:logs:ap-southeast-2:111111111111:log-group:/yolo/my-app')
            ->not->toEndWith(':*');
    }
});

it('does not call the CloudWatch Logs tag APIs during a dry-run', function () {
    $captured = [];

    bindMockCloudWatchLogsClient([
        new Result([
            'logGroups' => [[
                'logGroupName' => '/yolo/my-app',
                'arn' => 'arn:aws:logs:ap-southeast-2:111111111111:log-group:/yolo/my-app:*',
                'retentionInDays' => 30,
            ]],
        ]),
    ], $captured);

    (new SyncTaskLogGroupStep())(['dry-run' => true]);

    $tagCalls = array_filter(
        $captured,
        fn (array $call) => in_array($call['name'], ['ListTagsForResource', 'TagResource'], true),
    );

    expect($tagCalls)->toBeEmpty();
});
