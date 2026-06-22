<?php

use Aws\Result;
use Aws\MockHandler;
use Aws\CommandInterface;
use Codinglabs\Yolo\Helpers;
use GuzzleHttp\Promise\Create;
use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Codinglabs\Yolo\Steps\Sync\App\SyncTaskLogGroupStep;

/**
 * Bind a mock CloudWatch Logs client with command-routed responses. Captured calls
 * are written into $captured so tests can inspect arg shapes.
 *
 * @param  array<string, Result>  $byCommand  command-name => Result (used for any number of calls to that command)
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured  filled by reference
 */
function bindMockCloudWatchLogsClient(array $byCommand, array &$captured): void
{
    $mock = new class($byCommand, $captured) extends MockHandler
    {
        public function __construct(protected array $byCommand, protected array &$captured) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            $this->captured[] = ['name' => $cmd->getName(), 'args' => $cmd->toArray()];

            $result = $this->byCommand[$cmd->getName()] ?? new Result();

            return Create::promiseFor($result);
        }
    };

    Helpers::app()->instance('cloudWatchLogs', new CloudWatchLogsClient([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['log-group' => '/yolo/my-app']],
    ]);
});

it('strips the stream wildcard `:*` suffix before calling the CloudWatch Logs tag APIs', function (): void {
    $captured = [];

    bindMockCloudWatchLogsClient([
        // DescribeLogGroups — returns the log group with the wildcard-suffixed `arn`
        // that AWS actually returns for log groups. Called multiple times (exists,
        // currentRetentionInDays, arn) — same response each time, no memoisation.
        'DescribeLogGroups' => new Result([
            'logGroups' => [[
                'logGroupName' => '/yolo/my-app',
                'arn' => 'arn:aws:logs:ap-southeast-2:111111111111:log-group:/yolo/my-app:*',
                'retentionInDays' => 30,
            ]],
        ]),
        'ListTagsForResource' => new Result(['tags' => []]),
        'TagResource' => new Result(),
    ], $captured);

    (new SyncTaskLogGroupStep())([]);

    $tagCalls = array_values(array_filter(
        $captured,
        fn (array $call): bool => in_array($call['name'], ['ListTagsForResource', 'TagResource'], true),
    ));

    expect($tagCalls)->not->toBeEmpty();

    foreach ($tagCalls as $call) {
        expect($call['args']['resourceArn'])
            ->toBe('arn:aws:logs:ap-southeast-2:111111111111:log-group:/yolo/my-app')
            ->not->toEndWith(':*');
    }
});

it('reads tags during a dry-run for plan-time drift but never writes', function (): void {
    // The plan pass needs to know whether tag sync would change anything (so
    // the apply-pending filter doesn't drop a step with tag drift),
    // so ListTagsForResource is expected — but TagResource (the write) is not.
    $captured = [];

    bindMockCloudWatchLogsClient([
        'DescribeLogGroups' => new Result([
            'logGroups' => [[
                'logGroupName' => '/yolo/my-app',
                'arn' => 'arn:aws:logs:ap-southeast-2:111111111111:log-group:/yolo/my-app:*',
                'retentionInDays' => 30,
            ]],
        ]),
    ], $captured);

    (new SyncTaskLogGroupStep())(['dry-run' => true]);

    expect(array_column($captured, 'name'))
        ->not->toContain('TagResource');
});
