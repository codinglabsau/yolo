<?php

use Aws\Result;
use Aws\Command;
use Aws\MockHandler;
use Aws\CommandInterface;
use Codinglabs\Yolo\Helpers;
use GuzzleHttp\Promise\Create;
use Codinglabs\Yolo\Enums\StepResult;
use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\EventBridge\Exception\EventBridgeException;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncIvsEventBridgeRuleStep;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncIvsEventBridgeTargetStep;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncIvsCloudWatchLogGroupStep;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

function bindIvsCloudWatchLogsClient(array &$calls, bool $exists = true): void
{
    $mock = new class($calls, $exists) extends MockHandler
    {
        public function __construct(public array &$calls, protected bool $exists) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            $this->calls[] = $cmd->getName();

            return Create::promiseFor(match ($cmd->getName()) {
                'DescribeLogGroups' => new Result(['logGroups' => $this->exists ? [[
                    'logGroupName' => '/aws/ivs/yolo-testing',
                    'arn' => 'arn:aws:logs:ap-southeast-2:111111111111:log-group:/aws/ivs/yolo-testing',
                    'retentionInDays' => 14,
                ]] : []]),
                default => new Result([]),
            });
        }
    };

    Helpers::app()->instance('cloudWatchLogs', new CloudWatchLogsClient([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

it('skips the whole pipeline on a greenfield environment — no bucket, nothing exists', function (string $step): void {
    $captured = [];
    bindServiceLifecycleWorld(['bucket' => false], $captured);

    $calls = [];
    bindIvsCloudWatchLogsClient($calls, exists: false);

    $ebCaptured = [];
    bindRoutedEventBridgeClient([
        'DescribeRule' => new EventBridgeException('nope', new Command('DescribeRule')),
    ], $ebCaptured);

    expect((new $step())(['dry-run' => true]))->toBe(StepResult::SKIPPED);
})->with([
    SyncIvsCloudWatchLogGroupStep::class,
    SyncIvsEventBridgeRuleStep::class,
    SyncIvsEventBridgeTargetStep::class,
]);

it('provisions the env-shared log group when the offer and a live claim both hold', function (): void {
    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => "services:\n  ivs: {}\n",
        'claims' => ['my-app' => ['ivs']],
        'clusters' => ['my-app' => true],
    ], $captured);

    $calls = [];
    bindIvsCloudWatchLogsClient($calls);

    expect((new SyncIvsCloudWatchLogGroupStep())([]))->toBe(StepResult::SYNCED);
    expect($calls)->toContain('DescribeLogGroups');
});

it('skips provisioning while the offer is unclaimed and nothing exists', function (): void {
    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => "services:\n  ivs: {}\n",
        'claims' => ['my-app' => []],
        'clusters' => ['my-app' => true],
    ], $captured);

    $calls = [];
    bindIvsCloudWatchLogsClient($calls, exists: false);

    expect((new SyncIvsCloudWatchLogGroupStep())(['dry-run' => true]))->toBe(StepResult::SKIPPED);
});

it('plans WOULD_DELETE for an existing log group when the gate turns off, and deletes on apply', function (): void {
    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => "services:\n  ivs: {}\n",
        'claims' => ['my-app' => []],
        'clusters' => ['my-app' => true],
    ], $captured);

    $calls = [];
    bindIvsCloudWatchLogsClient($calls);

    // Plan pass: drift recorded before the dry-run guard, nothing written.
    $planned = new SyncIvsCloudWatchLogGroupStep();
    expect($planned(['dry-run' => true]))->toBe(StepResult::WOULD_DELETE);
    expect($planned->changes())->not->toBeEmpty();
    expect($calls)->not->toContain('DeleteLogGroup');

    // Apply pass: the log group (and its EventBridge delivery policy) goes.
    expect((new SyncIvsCloudWatchLogGroupStep())([]))->toBe(StepResult::DELETED);
    expect($calls)->toContain('DeleteLogGroup');
});

it('plans WOULD_DELETE for the rule when the gate turns off, removing rule and target in one act on apply', function (): void {
    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => "services:\n  ivs: {}\n",
        'claims' => ['my-app' => []],
        'clusters' => ['my-app' => true],
    ], $captured);

    $ebCaptured = [];
    bindRoutedEventBridgeClient([
        'DescribeRule' => new Result(['Name' => 'yolo-testing-ivs-state-change', 'Arn' => 'arn:aws:events:ap-southeast-2:111111111111:rule/yolo-testing-ivs-state-change']),
    ], $ebCaptured);

    $planned = new SyncIvsEventBridgeRuleStep();
    expect($planned(['dry-run' => true]))->toBe(StepResult::WOULD_DELETE);
    expect($planned->changes())->not->toBeEmpty();
    expect(array_column($ebCaptured, 'name'))->not->toContain('DeleteRule');

    expect((new SyncIvsEventBridgeRuleStep())([]))->toBe(StepResult::DELETED);

    $names = array_column($ebCaptured, 'name');
    expect($names)->toContain('RemoveTargets')->toContain('DeleteRule');
    expect(array_search('RemoveTargets', $names))->toBeLessThan(array_search('DeleteRule', $names));
});

it('the target step skips on teardown — the rule deletion removes its own target', function (): void {
    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => "services:\n  ivs: {}\n",
        'claims' => ['my-app' => []],
        'clusters' => ['my-app' => true],
    ], $captured);

    $ebCaptured = [];
    bindRoutedEventBridgeClient([
        'DescribeRule' => new Result(['Name' => 'yolo-testing-ivs-state-change']),
        'ListTargetsByRule' => new Result(['Targets' => [['Id' => 'ivs-cloudwatch-logs', 'Arn' => 'arn:x']]]),
    ], $ebCaptured);

    expect((new SyncIvsEventBridgeTargetStep())(['dry-run' => true]))->toBe(StepResult::SKIPPED);
    expect(array_column($ebCaptured, 'name'))->not->toContain('RemoveTargets');
});

it('holds position (skips) while a live app has not published its claim file', function (): void {
    $captured = [];
    bindServiceLifecycleWorld([
        'manifest' => "services:\n  ivs: {}\n",
        'claims' => [],
        'clusters' => ['my-app' => true],
    ], $captured);

    $calls = [];
    bindIvsCloudWatchLogsClient($calls);

    expect((new SyncIvsCloudWatchLogGroupStep())(['dry-run' => true]))->toBe(StepResult::SKIPPED);
    expect($calls)->not->toContain('DeleteLogGroup');
});
