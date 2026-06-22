<?php

use Aws\Result;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncLoadBalancerSecurityGroupStep;

/**
 * An existing load balancer SG that already has both public ingress rules but is
 * missing the yolo:scope marker — the real-world shape an alpha-era / pre-scope
 * group audits as `rogue` until sync re-stamps it. The DescribeSecurityGroupRules
 * queue answers the HTTP lookup first, then HTTPS, each already matching desired.
 *
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
 */
function bindExistingLoadBalancerSgMissingScope(array &$captured): void
{
    bindMockEc2Client([
        'DescribeSecurityGroups' => new Result(['SecurityGroups' => [
            ['GroupName' => 'yolo-testing-load-balancer-security-group', 'GroupId' => 'sg-lb123'],
        ]]),
        'DescribeTags' => new Result(['Tags' => [
            ['Key' => 'Name', 'Value' => 'yolo-testing-load-balancer-security-group'],
            ['Key' => 'yolo:environment', 'Value' => 'testing'],
        ]]),
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

it('stamps the missing yolo:scope tag on an existing security group when applying', function (): void {
    // Regression guard: this branch used to call synchroniseTags() with no
    // argument, which threw ArgumentCountError once #59 made $apply required —
    // so the env-scope marker never landed and the group stayed `rogue`.
    $captured = [];

    bindExistingLoadBalancerSgMissingScope($captured);

    expect((new SyncLoadBalancerSecurityGroupStep())([]))->toBe(StepResult::SYNCED);

    $createTags = collect($captured)->firstWhere('name', 'CreateTags');
    expect($createTags)->not->toBeNull();
    expect($createTags['args']['Tags'])->toContain(['Key' => 'yolo:scope', 'Value' => 'env']);

    // The rules already matched, so nothing about the ingress should be touched.
    expect(array_column($captured, 'name'))
        ->not->toContain('AuthorizeSecurityGroupIngress')
        ->not->toContain('ModifySecurityGroupRules');
});

it('surfaces the missing yolo:scope tag as a plan-time change without writing during a dry-run', function (): void {
    // Tag drift alone must mark the step WOULD_SYNC so the apply pass isn't
    // dropped by the only-pending-steps filter — the rules are clean here,
    // so the WOULD_SYNC can only come from the tag.
    $captured = [];

    bindExistingLoadBalancerSgMissingScope($captured);

    expect((new SyncLoadBalancerSecurityGroupStep())(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC);

    expect(array_column($captured, 'name'))
        ->not->toContain('CreateTags')
        ->not->toContain('AuthorizeSecurityGroupIngress');
});
