<?php

use Aws\Result;
use Aws\Command;
use Aws\MockHandler;
use Aws\CommandInterface;
use Codinglabs\Yolo\Helpers;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Promise\Create;
use Aws\S3\Exception\S3Exception;
use Codinglabs\Yolo\Enums\StepResult;
use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncIvsEventBridgeRuleStep;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncIvsEventBridgeTargetStep;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncIvsCloudWatchLogGroupStep;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

it('skips the env IVS pipeline when the environment manifest does not declare services.ivs', function (string $step): void {
    // No env manifest in the bucket at all — nothing declared.
    $captured = [];
    bindRoutedS3Client([
        'GetObject' => new S3Exception('Not Found', new Command('GetObject'), [
            'response' => new Response(404),
        ]),
    ], $captured);

    expect((new $step())([]))->toBe(StepResult::SKIPPED);
})->with([
    SyncIvsCloudWatchLogGroupStep::class,
    SyncIvsEventBridgeRuleStep::class,
    SyncIvsEventBridgeTargetStep::class,
]);

it('provisions the env-shared log group when the environment manifest declares services.ivs', function (): void {
    $captured = [];
    bindRoutedS3Client([
        'GetObject' => new Result(['Body' => "services:\n  ivs: {}\n"]),
    ], $captured);

    $calls = [];
    $mock = new class($calls) extends MockHandler
    {
        public function __construct(public array &$calls) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            $this->calls[] = $cmd->getName();

            return Create::promiseFor(match ($cmd->getName()) {
                'DescribeLogGroups' => new Result(['logGroups' => [[
                    'logGroupName' => '/aws/ivs/yolo-testing',
                    'arn' => 'arn:aws:logs:ap-southeast-2:111111111111:log-group:/aws/ivs/yolo-testing',
                    'retentionInDays' => 14,
                ]]]),
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

    expect((new SyncIvsCloudWatchLogGroupStep())([]))->toBe(StepResult::SYNCED);
    expect($calls)->toContain('DescribeLogGroups');
});
