<?php

use Aws\Result;
use Aws\MockHandler;
use Aws\CommandInterface;
use Codinglabs\Yolo\Helpers;
use GuzzleHttp\Promise\Create;
use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Codinglabs\Yolo\Resources\CloudWatchLogs\IvsLogGroup;

/**
 * Bind a CloudWatch Logs client whose DescribeLogGroups returns one IVS log group
 * with the supplied retention and whose DescribeResourcePolicies returns the
 * supplied policies. Records command names for write assertions.
 *
 * @param  array<int, array<string, mixed>>  $resourcePolicies
 */
function bindRecordingCloudWatchLogsClient(?int $retentionInDays, array $resourcePolicies = []): object
{
    $recorder = new class($retentionInDays, $resourcePolicies) extends MockHandler
    {
        /** @var array<int, string> */
        public array $calls = [];

        public function __construct(public ?int $retentionInDays, public array $resourcePolicies) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            $this->calls[] = $cmd->getName();

            return Create::promiseFor(match ($cmd->getName()) {
                'DescribeLogGroups' => new Result(['logGroups' => [array_filter([
                    'logGroupName' => '/aws/ivs/yolo-testing',
                    'arn' => 'arn:aws:logs:ap-southeast-2:111111111111:log-group:/aws/ivs/yolo-testing',
                    'retentionInDays' => $this->retentionInDays,
                ], fn (int|string|null $value): bool => $value !== null)]]),
                'DescribeResourcePolicies' => new Result(['resourcePolicies' => $this->resourcePolicies]),
                default => new Result([]),
            });
        }
    };

    Helpers::app()->instance('cloudWatchLogs', new CloudWatchLogsClient([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $recorder,
    ]));

    return $recorder;
}

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'services' => ['ivs'],
    ]);
});

it('reconciles retention and the eventbridge resource policy when both have drifted', function (): void {
    $recorder = bindRecordingCloudWatchLogsClient(retentionInDays: 7, resourcePolicies: []);

    $attributes = collect((new IvsLogGroup())->synchroniseConfiguration())->pluck('attribute');

    expect($attributes)->toContain('retention-days')->toContain('eventbridge-resource-policy');
    expect($recorder->calls)->toContain('PutRetentionPolicy')->toContain('PutResourcePolicy');
});

it('does not touch retention when it already matches the manifest default', function (): void {
    // Retention matches (14), but the resource policy is absent — proving retention
    // is diffed (not blindly re-put) while the missing policy is still reported.
    $recorder = bindRecordingCloudWatchLogsClient(retentionInDays: 14, resourcePolicies: []);

    $attributes = collect((new IvsLogGroup())->synchroniseConfiguration())->pluck('attribute');

    expect($attributes)->not->toContain('retention-days')->toContain('eventbridge-resource-policy');
    expect($recorder->calls)->not->toContain('PutRetentionPolicy');
});

it('computes the diff without writing under apply:false', function (): void {
    $recorder = bindRecordingCloudWatchLogsClient(retentionInDays: 7, resourcePolicies: []);

    expect((new IvsLogGroup())->synchroniseConfiguration(apply: false))->not->toBeEmpty();
    expect($recorder->calls)
        ->not->toContain('PutRetentionPolicy')
        ->not->toContain('PutResourcePolicy');
});
