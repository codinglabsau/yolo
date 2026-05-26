<?php

use Aws\Result;
use Aws\MockHandler;
use Aws\CommandInterface;
use Codinglabs\Yolo\Helpers;
use GuzzleHttp\Promise\Create;
use Codinglabs\Yolo\Resources\Fargate\TargetGroup;
use Aws\ElasticLoadBalancingV2\ElasticLoadBalancingV2Client;

beforeEach(function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
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
        'HealthCheckPath' => '/health',
        'HealthCheckIntervalSeconds' => 10,
        'HealthCheckTimeoutSeconds' => 5,
        'HealthyThresholdCount' => 2,
        'UnhealthyThresholdCount' => 3,
        'Matcher' => ['HttpCode' => '200'],
    ], $overrides);
}

function deregistrationDelayAttributes(string $value): array
{
    return [['Key' => 'deregistration_delay.timeout_seconds', 'Value' => $value]];
}

it('shares one health-check config between create and sync', function () {
    expect(TargetGroup::reconcilableHealthCheck())->toBe([
        'HealthCheckPath' => '/health',
        'HealthCheckIntervalSeconds' => 10,
        'HealthCheckTimeoutSeconds' => 5,
        'HealthyThresholdCount' => 2,
        'UnhealthyThresholdCount' => 3,
        'Matcher' => ['HttpCode' => '200'],
    ]);
});

it('modifies the target group when a health-check field has drifted', function () {
    $recorder = bindRecordingElbV2Client(liveTargetGroup(['HealthCheckIntervalSeconds' => 30]), deregistrationDelayAttributes('10'));

    (new TargetGroup())->synchroniseConfiguration();

    expect($recorder->calls)->toContain('ModifyTargetGroup');
});

it('makes no write when health-check and deregistration both already match', function () {
    $recorder = bindRecordingElbV2Client(liveTargetGroup(), deregistrationDelayAttributes('10'));

    (new TargetGroup())->synchroniseConfiguration();

    expect($recorder->calls)->not->toContain('ModifyTargetGroup');
    expect($recorder->calls)->not->toContain('ModifyTargetGroupAttributes');
});

it('reads the live target group only once (reuses the lookup, no second describe)', function () {
    $recorder = bindRecordingElbV2Client(liveTargetGroup(['HealthCheckIntervalSeconds' => 30]));

    (new TargetGroup())->synchroniseConfiguration();

    expect(collect($recorder->calls)->filter(fn ($c) => $c === 'DescribeTargetGroups'))->toHaveCount(1);
});

it('defaults the deregistration delay to 10s', function () {
    expect((new TargetGroup())->deregistrationDelay())->toBe(10);
});

it('respects a manifest stop-grace override', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'tasks' => ['web' => ['stop-grace' => 30]],
    ]);

    expect((new TargetGroup())->deregistrationDelay())->toBe(30);
});

it('caps the deregistration delay when it is still on the AWS 300s default', function () {
    $recorder = bindRecordingElbV2Client(liveTargetGroup(), deregistrationDelayAttributes('300'));

    (new TargetGroup())->synchroniseConfiguration();

    expect($recorder->calls)->toContain('ModifyTargetGroupAttributes');
});
