<?php

use Aws\Result;
use Aws\MockHandler;
use Aws\CommandInterface;
use Codinglabs\Yolo\Helpers;
use GuzzleHttp\Promise\Create;
use Codinglabs\Yolo\Resources\ElbV2\TargetGroup;
use Aws\ElasticLoadBalancingV2\ElasticLoadBalancingV2Client;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

/**
 * Bind an ELBv2 client whose Describe* calls return the supplied target group +
 * attributes, recording every command name so tests can assert which write calls
 * fired. Returns the recorder so the test can read `$recorder->calls`.
 */
function bindRecordingElbV2Client(array $targetGroup, array $attributes = [['Key' => 'deregistration_delay.timeout_seconds', 'Value' => '300']]): object
{
    $recorder = new class($targetGroup, $attributes) extends MockHandler
    {
        /** @var array<int, string> */
        public array $calls = [];

        public function __construct(public array $targetGroup, public array $attributes) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            $this->calls[] = $cmd->getName();

            return Create::promiseFor(match ($cmd->getName()) {
                'DescribeTargetGroups' => new Result(['TargetGroups' => [$this->targetGroup]]),
                'DescribeTargetGroupAttributes' => new Result(['Attributes' => $this->attributes]),
                default => new Result([]),
            });
        }
    };

    Helpers::app()->instance('elasticLoadBalancingV2', new ElasticLoadBalancingV2Client([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $recorder,
    ]));

    return $recorder;
}

function liveTargetGroup(array $overrides = []): array
{
    return array_merge([
        'TargetGroupArn' => 'arn:aws:elasticloadbalancing:ap-southeast-2:111111111111:targetgroup/yolo-testing-my-app/abc',
        'HealthCheckPath' => '/up',
        'HealthCheckIntervalSeconds' => 10,
        'HealthCheckTimeoutSeconds' => 8,
        'HealthyThresholdCount' => 2,
        'UnhealthyThresholdCount' => 5,
        'Matcher' => ['HttpCode' => '200'],
    ], $overrides);
}

function deregistrationDelayAttributes(string $value): array
{
    return [['Key' => 'deregistration_delay.timeout_seconds', 'Value' => $value]];
}

it('shares one health-check config between create and sync', function (): void {
    expect(TargetGroup::reconcilableHealthCheck())->toBe([
        'HealthCheckPath' => '/up',
        'HealthCheckIntervalSeconds' => 10,
        'HealthCheckTimeoutSeconds' => 8,
        'HealthyThresholdCount' => 2,
        'UnhealthyThresholdCount' => 5,
        'Matcher' => ['HttpCode' => '200'],
    ]);
});

it('lets the manifest override each health-check field', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['health-check' => [
            'path' => '/health',
            'interval' => 15,
            'timeout' => 10,
            'healthy-threshold' => 3,
            'unhealthy-threshold' => 4,
        ]]],
    ]);

    expect(TargetGroup::reconcilableHealthCheck())->toBe([
        'HealthCheckPath' => '/health',
        'HealthCheckIntervalSeconds' => 15,
        'HealthCheckTimeoutSeconds' => 10,
        'HealthyThresholdCount' => 3,
        'UnhealthyThresholdCount' => 4,
        'Matcher' => ['HttpCode' => '200'],
    ]);
});

it('reconciles a target group still on the old aggressive health-check defaults to the tolerant ones', function (): void {
    // CL's live target group sits on the pre-tolerance values (5s timeout, 3
    // unhealthy). A plain `yolo sync` must drag it onto the new defaults via
    // ModifyTargetGroup — the health check is reconciled, not create-only.
    $recorder = bindRecordingElbV2Client(
        liveTargetGroup(['HealthCheckTimeoutSeconds' => 5, 'UnhealthyThresholdCount' => 3]),
        deregistrationDelayAttributes('10'),
    );

    $changes = collect((new TargetGroup())->synchroniseConfiguration());

    expect($recorder->calls)->toContain('ModifyTargetGroup');

    $timeout = $changes->firstWhere('attribute', 'HealthCheckTimeoutSeconds');
    expect($timeout->from)->toBe('5');
    expect($timeout->to)->toBe('8');

    $unhealthy = $changes->firstWhere('attribute', 'UnhealthyThresholdCount');
    expect($unhealthy->from)->toBe('3');
    expect($unhealthy->to)->toBe('5');
});

it('modifies the target group when a health-check field has drifted', function (): void {
    $recorder = bindRecordingElbV2Client(liveTargetGroup(['HealthCheckIntervalSeconds' => 30]), deregistrationDelayAttributes('10'));

    (new TargetGroup())->synchroniseConfiguration();

    expect($recorder->calls)->toContain('ModifyTargetGroup');
});

it('makes no write when health-check and deregistration both already match', function (): void {
    $recorder = bindRecordingElbV2Client(liveTargetGroup(), deregistrationDelayAttributes('10'));

    (new TargetGroup())->synchroniseConfiguration();

    expect($recorder->calls)->not->toContain('ModifyTargetGroup');
    expect($recorder->calls)->not->toContain('ModifyTargetGroupAttributes');
});

it('reads the live target group only once (reuses the lookup, no second describe)', function (): void {
    $recorder = bindRecordingElbV2Client(liveTargetGroup(['HealthCheckIntervalSeconds' => 30]));

    (new TargetGroup())->synchroniseConfiguration();

    expect(collect($recorder->calls)->filter(fn ($c): bool => $c === 'DescribeTargetGroups'))->toHaveCount(1);
});

it('defaults the deregistration delay to 10s', function (): void {
    expect((new TargetGroup())->deregistrationDelay())->toBe(10);
});

it('respects a manifest shutdown-grace-period override', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['shutdown-grace-period' => 30]],
    ]);

    expect((new TargetGroup())->deregistrationDelay())->toBe(30);
});

it('caps the deregistration delay when it is still on the AWS 300s default', function (): void {
    $recorder = bindRecordingElbV2Client(liveTargetGroup(), deregistrationDelayAttributes('300'));

    (new TargetGroup())->synchroniseConfiguration();

    expect($recorder->calls)->toContain('ModifyTargetGroupAttributes');
});

it('returns the drifted health-check field as a current → desired change', function (): void {
    bindRecordingElbV2Client(liveTargetGroup(['HealthCheckIntervalSeconds' => 30]), deregistrationDelayAttributes('10'));

    $change = collect((new TargetGroup())->synchroniseConfiguration())
        ->firstWhere('attribute', 'HealthCheckIntervalSeconds');

    expect($change)->not->toBeNull();
    expect($change->from)->toBe('30');
    expect($change->to)->toBe('10');
});

it('returns the deregistration delay as a change when on the AWS default', function (): void {
    bindRecordingElbV2Client(liveTargetGroup(), deregistrationDelayAttributes('300'));

    $change = collect((new TargetGroup())->synchroniseConfiguration())
        ->firstWhere('attribute', 'deregistration_delay.timeout_seconds');

    expect($change->from)->toBe('300');
    expect($change->to)->toBe('10');
});

it('returns no changes when health-check and deregistration both match', function (): void {
    bindRecordingElbV2Client(liveTargetGroup(), deregistrationDelayAttributes('10'));

    expect((new TargetGroup())->synchroniseConfiguration())->toBe([]);
});

it('computes the diff without writing under apply:false', function (): void {
    $recorder = bindRecordingElbV2Client(liveTargetGroup(['HealthCheckIntervalSeconds' => 30]), deregistrationDelayAttributes('10'));

    expect((new TargetGroup())->synchroniseConfiguration(apply: false))->not->toBeEmpty();
    expect($recorder->calls)->not->toContain('ModifyTargetGroup');
});
