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
 * Bind an ELBv2 client whose DescribeTargetGroups returns the supplied target
 * group, recording every command name so tests can assert whether (and which)
 * write calls fired. Returns the recorder so the test can read `$recorder->calls`.
 */
function bindRecordingElbV2Client(array $targetGroup): object
{
    $recorder = new class($targetGroup) extends MockHandler
    {
        /** @var array<int, string> */
        public array $calls = [];

        public function __construct(public array $targetGroup) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            $this->calls[] = $cmd->getName();

            return Create::promiseFor(
                $cmd->getName() === 'DescribeTargetGroups'
                    ? new Result(['TargetGroups' => [$this->targetGroup]])
                    : new Result([])
            );
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
    $recorder = bindRecordingElbV2Client(liveTargetGroup(['HealthCheckIntervalSeconds' => 30]));

    (new TargetGroup())->synchroniseConfiguration();

    expect($recorder->calls)->toContain('ModifyTargetGroup');
});

it('does not modify the target group when the live config already matches', function () {
    $recorder = bindRecordingElbV2Client(liveTargetGroup());

    (new TargetGroup())->synchroniseConfiguration();

    expect($recorder->calls)->not->toContain('ModifyTargetGroup');
});

it('reads the live target group only once (reuses the lookup, no second describe)', function () {
    $recorder = bindRecordingElbV2Client(liveTargetGroup(['HealthCheckIntervalSeconds' => 30]));

    (new TargetGroup())->synchroniseConfiguration();

    expect(collect($recorder->calls)->filter(fn ($c) => $c === 'DescribeTargetGroups'))->toHaveCount(1);
});
