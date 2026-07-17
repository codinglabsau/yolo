<?php

use Aws\Result;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncLoadBalancerSecurityGroupStep;

/**
 * An existing load balancer SG carrying the given live tags, with both public
 * ingress rules already matching desired. The DescribeSecurityGroupRules queue
 * answers the HTTP lookup first, then HTTPS.
 *
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
 * @param  array<int, array{Key: string, Value: string}>  $liveTags
 */
function bindExistingLoadBalancerSg(array &$captured, array $liveTags): void
{
    bindMockEc2Client([
        'DescribeVpcs' => new Result(['Vpcs' => [['VpcId' => 'vpc-1']]]),
        'DescribeSecurityGroups' => new Result(['SecurityGroups' => [
            ['GroupName' => 'yolo-testing-load-balancer-security-group', 'GroupId' => 'sg-lb123', 'VpcId' => 'vpc-1'],
        ]]),
        'DescribeTags' => new Result(['Tags' => $liveTags]),
        'DescribeSecurityGroupRules' => [
            new Result(['SecurityGroupRules' => [
                ['SecurityGroupRuleId' => 'sgr-http', 'IpProtocol' => 'tcp', 'FromPort' => 80, 'ToPort' => 80, 'CidrIpv4' => '0.0.0.0/0'],
            ]]),
            new Result(['SecurityGroupRules' => [
                ['SecurityGroupRuleId' => 'sgr-https', 'IpProtocol' => 'tcp', 'FromPort' => 443, 'ToPort' => 443, 'CidrIpv4' => '0.0.0.0/0'],
            ]]),
        ],
        'CreateTags' => new Result(),
    ], $captured);
}

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

it('creates the load balancer security group and authorises HTTP/HTTPS when absent', function (): void {
    $captured = [];

    bindMockEc2Client([
        'DescribeSecurityGroups' => [
            new Result(['SecurityGroups' => []]),   // first lookup → not found → create
            new Result(['SecurityGroups' => [['GroupName' => 'yolo-testing-load-balancer-security-group', 'GroupId' => 'sg-lb123']]]),
        ],
        'DescribeVpcs' => new Result(['Vpcs' => [['VpcId' => 'vpc-1']]]),
        'CreateSecurityGroup' => new Result(['GroupId' => 'sg-lb123']),
        'AuthorizeSecurityGroupIngress' => new Result(),
    ], $captured);

    expect((new SyncLoadBalancerSecurityGroupStep())([]))->toBe(StepResult::CREATED);

    $names = array_column($captured, 'name');
    expect($names)->toContain('CreateSecurityGroup');
    // Both the :80 and :443 public rules are authorised on a fresh group.
    expect(collect($captured)->where('name', 'AuthorizeSecurityGroupIngress'))->toHaveCount(2);
});

it('scopes the security group lookup to the environment VPC', function (): void {
    // Group names are only unique per VPC — an unscoped lookup can match a
    // same-named group in another VPC (another deployment generation on the
    // account) and hand its id to CreateLoadBalancer, which AWS rejects.
    $captured = [];

    bindExistingLoadBalancerSg($captured, [
        ['Key' => 'Name', 'Value' => 'yolo-testing-load-balancer-security-group'],
        ['Key' => 'yolo:scope', 'Value' => 'env'],
        ['Key' => 'yolo:environment', 'Value' => 'testing'],
    ]);

    (new SyncLoadBalancerSecurityGroupStep())(['dry-run' => true]);

    $lookup = collect($captured)->firstWhere('name', 'DescribeSecurityGroups');
    expect($lookup['args']['Filters'])->toContain(
        ['Name' => 'group-name', 'Values' => ['yolo-testing-load-balancer-security-group']],
        ['Name' => 'vpc-id', 'Values' => ['vpc-1']],
    );
});

it('plans the create when the environment VPC does not exist yet', function (): void {
    // Greenfield plan pass (the two-pass contract): with no VPC to scope the
    // lookup to, the group reads as absent — never a plan-pass crash.
    $captured = [];

    bindMockEc2Client([
        'DescribeVpcs' => new Result(['Vpcs' => []]),
    ], $captured);

    expect((new SyncLoadBalancerSecurityGroupStep())(['dry-run' => true]))->toBe(StepResult::WOULD_CREATE);

    expect(array_column($captured, 'name'))->not->toContain('DescribeSecurityGroups');
});

it('refuses to adopt an existing security group with no yolo:scope ownership marker', function (): void {
    // A name-matched group with no ownership marker was not created by YOLO —
    // it may be another deployment tool's live group sharing the account.
    // Stamping YOLO tags on it would claim infrastructure that isn't ours, so
    // the sync must fail loudly instead (and write nothing).
    $captured = [];

    bindExistingLoadBalancerSg($captured, [
        ['Key' => 'Name', 'Value' => 'yolo-testing-load-balancer-security-group'],
        ['Key' => 'yolo:environment', 'Value' => 'testing'],
    ]);

    expect(fn (): StepResult => (new SyncLoadBalancerSecurityGroupStep())([]))
        ->toThrow(IntegrityCheckException::class, 'Refusing to adopt');

    expect(array_column($captured, 'name'))
        ->not->toContain('CreateTags')
        ->not->toContain('AuthorizeSecurityGroupIngress');
});

it('refuses the unowned group at plan time too, so the sync never reaches apply', function (): void {
    $captured = [];

    bindExistingLoadBalancerSg($captured, [
        ['Key' => 'Name', 'Value' => 'yolo-testing-load-balancer-security-group'],
    ]);

    expect(fn (): StepResult => (new SyncLoadBalancerSecurityGroupStep())(['dry-run' => true]))
        ->toThrow(IntegrityCheckException::class);

    expect(array_column($captured, 'name'))->not->toContain('CreateTags');
});

it('heals a lesser missing tag on a group that carries the yolo:scope marker', function (): void {
    // The ownership marker is present, so this IS our group — additive tag
    // sync (here the yolo:environment baseline) proceeds as before.
    $captured = [];

    bindExistingLoadBalancerSg($captured, [
        ['Key' => 'Name', 'Value' => 'yolo-testing-load-balancer-security-group'],
        ['Key' => 'yolo:scope', 'Value' => 'env'],
    ]);

    expect((new SyncLoadBalancerSecurityGroupStep())([]))->toBe(StepResult::SYNCED);

    $createTags = collect($captured)->firstWhere('name', 'CreateTags');
    expect($createTags)->not->toBeNull();
    expect($createTags['args']['Tags'])->toContain(['Key' => 'yolo:environment', 'Value' => 'testing']);

    // The rules already matched, so nothing about the ingress should be touched.
    expect(array_column($captured, 'name'))
        ->not->toContain('AuthorizeSecurityGroupIngress')
        ->not->toContain('ModifySecurityGroupRules');
});

it('surfaces owned-group tag drift as a plan-time change without writing during a dry-run', function (): void {
    // Tag drift alone must mark the step WOULD_SYNC so the apply pass isn't
    // dropped by the only-pending-steps filter — the rules are clean here,
    // so the WOULD_SYNC can only come from the tag.
    $captured = [];

    bindExistingLoadBalancerSg($captured, [
        ['Key' => 'Name', 'Value' => 'yolo-testing-load-balancer-security-group'],
        ['Key' => 'yolo:scope', 'Value' => 'env'],
    ]);

    expect((new SyncLoadBalancerSecurityGroupStep())(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC);

    expect(array_column($captured, 'name'))
        ->not->toContain('CreateTags')
        ->not->toContain('AuthorizeSecurityGroupIngress');
});
