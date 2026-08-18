<?php

use Aws\Result;
use Aws\MockHandler;
use Aws\CommandInterface;
use Codinglabs\Yolo\Helpers;
use GuzzleHttp\Promise\Create;
use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Codinglabs\Yolo\Resources\CloudWatchLogs\WafLogGroup;

/**
 * Bind a CloudWatch Logs client whose DescribeLogGroups returns the WAF log
 * group with the supplied retention. Records command names for write assertions.
 */
function bindRecordingWafCloudWatchLogsClient(?int $retentionInDays): object
{
    $recorder = new class($retentionInDays) extends MockHandler
    {
        /** @var array<int, string> */
        public array $calls = [];

        public function __construct(public ?int $retentionInDays) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            $this->calls[] = $cmd->getName();

            return Create::promiseFor(match ($cmd->getName()) {
                'DescribeLogGroups' => new Result(['logGroups' => [array_filter([
                    'logGroupName' => 'aws-waf-logs-yolo-testing',
                    'arn' => 'arn:aws:logs:ap-southeast-2:111111111111:log-group:aws-waf-logs-yolo-testing',
                    'retentionInDays' => $this->retentionInDays,
                ], fn (int|string|null $value): bool => $value !== null)]]),
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
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);
});

it('rides the WAFv2-mandated prefix ahead of the keyed name', function (): void {
    expect((new WafLogGroup())->name())->toBe('aws-waf-logs-yolo-testing')
        ->and((new WafLogGroup())->arn())->toBe('arn:aws:logs:ap-southeast-2:111111111111:log-group:aws-waf-logs-yolo-testing');
});

it('reconciles retention when it has drifted', function (): void {
    $recorder = bindRecordingWafCloudWatchLogsClient(retentionInDays: null);

    $attributes = collect((new WafLogGroup())->synchroniseConfiguration())->pluck('attribute');

    expect($attributes)->toContain('retention-days');
    expect($recorder->calls)->toContain('PutRetentionPolicy');
});

it('does not touch retention when it already matches', function (): void {
    $recorder = bindRecordingWafCloudWatchLogsClient(retentionInDays: 30);

    expect((new WafLogGroup())->synchroniseConfiguration())->toBe([]);
    expect($recorder->calls)->not->toContain('PutRetentionPolicy');
});

it('computes the retention diff without writing under apply:false', function (): void {
    $recorder = bindRecordingWafCloudWatchLogsClient(retentionInDays: 7);

    $attributes = collect((new WafLogGroup())->synchroniseConfiguration(apply: false))->pluck('attribute');

    expect($attributes)->toContain('retention-days');
    expect($recorder->calls)->not->toContain('PutRetentionPolicy');
});
