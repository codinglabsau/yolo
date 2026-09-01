<?php

use Aws\Result;
use Aws\MockHandler;
use Aws\CommandInterface;
use Codinglabs\Yolo\Helpers;
use GuzzleHttp\Promise\Create;
use Codinglabs\Yolo\Resources\ElbV2\LoadBalancer;
use Aws\ElasticLoadBalancingV2\ElasticLoadBalancingV2Client;

/**
 * Bind an ELBv2 client that records every command (name + args) and returns the
 * supplied load-balancer attributes from DescribeLoadBalancerAttributes. Returns
 * the recorder so tests can read `$recorder->calls`.
 *
 * @param  array<int, array{Key: string, Value: string}>  $attributes
 */
function bindRecordingLoadBalancerClient(array $attributes = []): object
{
    $recorder = new class($attributes) extends MockHandler
    {
        /** @var array<int, array{name: string, args: array<string, mixed>}> */
        public array $calls = [];

        public function __construct(public array $attributes) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            $this->calls[] = ['name' => $cmd->getName(), 'args' => $cmd->toArray()];

            return Create::promiseFor(match ($cmd->getName()) {
                'CreateLoadBalancer' => new Result([
                    'LoadBalancers' => [[
                        'LoadBalancerArn' => 'arn:aws:elasticloadbalancing:ap-southeast-2:111111111111:loadbalancer/app/yolo-testing/abc',
                    ]],
                ]),
                'DescribeLoadBalancers' => new Result([
                    'LoadBalancers' => [[
                        'LoadBalancerName' => 'yolo-testing',
                        'LoadBalancerArn' => 'arn:aws:elasticloadbalancing:ap-southeast-2:111111111111:loadbalancer/app/yolo-testing/abc',
                        // `active` so create()'s LoadBalancerAvailable waiter
                        // resolves on its first poll.
                        'State' => ['Code' => 'active'],
                    ]],
                ]),
                'DescribeLoadBalancerAttributes' => new Result(['Attributes' => $this->attributes]),
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

/**
 * Flatten a captured Attributes argument list into an associative Key => Value map.
 *
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $calls
 * @return array<string, string>
 */
function modifiedAttributes(array $calls): array
{
    $modify = collect($calls)->firstWhere('name', 'ModifyLoadBalancerAttributes');

    return collect($modify['args']['Attributes'])
        ->mapWithKeys(fn (array $attribute): array => [$attribute['Key'] => $attribute['Value']])
        ->all();
}

/**
 * The live-attribute shape DescribeLoadBalancerAttributes returns for an ALB that
 * already carries every attribute YOLO manages — used to prove a clean sync makes
 * no write. Defaults match the desired values (deletion protection on in every
 * environment).
 *
 * @param  array<string, string>  $overrides
 * @return array<int, array{Key: string, Value: string}>
 */
function syncedLoadBalancerAttributes(array $overrides = []): array
{
    $attributes = array_merge([
        'deletion_protection.enabled' => 'true',
        'access_logs.s3.enabled' => 'true',
        'access_logs.s3.bucket' => 'yolo-111111111111-testing-logs',
        'access_logs.s3.prefix' => 'alb/yolo-testing',
        'routing.http.drop_invalid_header_fields.enabled' => 'true',
        'routing.http2.enabled' => 'true',
        'idle_timeout.timeout_seconds' => '60',
    ], $overrides);

    return collect($attributes)
        ->map(fn (string $value, string $key): array => ['Key' => $key, 'Value' => $value])
        ->values()
        ->all();
}

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

it('waits for the load balancer to become active before reconciling attributes', function (): void {
    // A fresh ALB provisions for a minute or two; downstream env steps (notably the
    // WAF association) fail against a not-yet-active load balancer. create() must
    // block on the LoadBalancerAvailable waiter — a DescribeLoadBalancers poll —
    // after CreateLoadBalancer and before it writes attributes.
    $ec2 = [];

    bindMockEc2Client([
        'DescribeVpcs' => new Result(['Vpcs' => [['VpcId' => 'vpc-1']]]),
        'DescribeSecurityGroups' => new Result(['SecurityGroups' => [
            ['GroupName' => 'yolo-testing-load-balancer-security-group', 'GroupId' => 'sg-lb1'],
        ]]),
        'DescribeSubnets' => new Result(['Subnets' => [
            ['SubnetId' => 'subnet-1'],
        ]]),
    ], $ec2);

    $recorder = bindRecordingLoadBalancerClient();

    (new LoadBalancer())->create();

    $sequence = collect($recorder->calls)->pluck('name');

    expect($sequence)->toContain('DescribeLoadBalancers');
    expect($sequence->search('DescribeLoadBalancers'))
        ->toBeGreaterThan($sequence->search('CreateLoadBalancer'))
        ->toBeLessThan($sequence->search('ModifyLoadBalancerAttributes'));
});

it('pins the full hardened attribute shape', function (): void {
    // Hardcoded sensible defaults — the argument shape create + sync both push
    // through modifyLoadBalancerAttributes. Deletion protection is always on.
    expect((new LoadBalancer())->desiredAttributes())->toBe([
        'deletion_protection.enabled' => 'true',
        'access_logs.s3.enabled' => 'true',
        'access_logs.s3.bucket' => 'yolo-111111111111-testing-logs',
        'access_logs.s3.prefix' => 'alb/yolo-testing',
        'routing.http.drop_invalid_header_fields.enabled' => 'true',
        'routing.http2.enabled' => 'true',
        'idle_timeout.timeout_seconds' => '60',
    ]);
});

it('makes no write when every managed attribute already matches', function (): void {
    $recorder = bindRecordingLoadBalancerClient(syncedLoadBalancerAttributes());

    (new LoadBalancer())->synchroniseConfiguration();

    expect(collect($recorder->calls)->pluck('name'))->not->toContain('ModifyLoadBalancerAttributes');
});

it('modifies the load balancer when a managed attribute has drifted', function (): void {
    $recorder = bindRecordingLoadBalancerClient(
        syncedLoadBalancerAttributes(['routing.http.drop_invalid_header_fields.enabled' => 'false'])
    );

    (new LoadBalancer())->synchroniseConfiguration();

    expect(collect($recorder->calls)->pluck('name'))->toContain('ModifyLoadBalancerAttributes');
    expect(modifiedAttributes($recorder->calls)['routing.http.drop_invalid_header_fields.enabled'])->toBe('true');
});

it('returns the drifted attribute as a current → desired change', function (): void {
    bindRecordingLoadBalancerClient(syncedLoadBalancerAttributes(['idle_timeout.timeout_seconds' => '30']));

    $changes = (new LoadBalancer())->synchroniseConfiguration();

    expect($changes)->toHaveCount(1);
    expect($changes[0]->attribute)->toBe('idle_timeout.timeout_seconds');
    expect($changes[0]->from)->toBe('30');
    expect($changes[0]->to)->toBe('60');
});

it('returns no changes when every managed attribute matches', function (): void {
    bindRecordingLoadBalancerClient(syncedLoadBalancerAttributes());

    expect((new LoadBalancer())->synchroniseConfiguration())->toBe([]);
});

it('computes the diff without writing under apply:false', function (): void {
    $recorder = bindRecordingLoadBalancerClient(syncedLoadBalancerAttributes(['routing.http2.enabled' => 'false']));

    $changes = (new LoadBalancer())->synchroniseConfiguration(apply: false);

    expect($changes)->toHaveCount(1);
    expect($changes[0]->attribute)->toBe('routing.http2.enabled');
    expect(collect($recorder->calls)->pluck('name'))->not->toContain('ModifyLoadBalancerAttributes');
});
