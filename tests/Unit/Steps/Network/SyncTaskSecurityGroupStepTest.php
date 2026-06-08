<?php

use Aws\Result;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\App\SyncTaskSecurityGroupStep;

function describeTaskAndLoadBalancerGroups(): Result
{
    return new Result([
        'SecurityGroups' => [
            ['GroupName' => 'yolo-testing-my-app-ecs-task-security-group', 'GroupId' => 'sg-task456'],
            ['GroupName' => 'yolo-testing-load-balancer-security-group', 'GroupId' => 'sg-lb789'],
        ],
    ]);
}

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

it('authorises the load-balancer ingress rule on the apply pass when the dry-run key is absent', function (): void {
    // Regression: the apply pass flows the raw input options through, which no
    // longer carry a `dry-run` key (the option was dropped). The step must coerce
    // the absent flag to false rather than handing null to a bool-typed param.
    $captured = [];

    bindMockEc2Client([
        'DescribeSecurityGroups' => describeTaskAndLoadBalancerGroups(),
        'DescribeSecurityGroupRules' => new Result(['SecurityGroupRules' => []]),
        'AuthorizeSecurityGroupIngress' => new Result(),
    ], $captured);

    expect((new SyncTaskSecurityGroupStep())([]))->toBe(StepResult::SYNCED);

    $authorise = collect($captured)->firstWhere('name', 'AuthorizeSecurityGroupIngress');
    expect($authorise)->not->toBeNull();

    $permission = $authorise['args']['IpPermissions'][0];
    expect($permission['FromPort'])->toBe(8000);
    expect($permission['ToPort'])->toBe(8000);
    expect($permission['UserIdGroupPairs'][0]['GroupId'])->toBe('sg-lb789');
    expect($authorise['args']['GroupId'])->toBe('sg-task456');

    // Purely additive — it must never revoke an existing rule.
    expect(array_column($captured, 'name'))->not->toContain('RevokeSecurityGroupIngress');
});

it('does not authorise again when a matching load-balancer ingress rule already exists', function (): void {
    $captured = [];

    bindMockEc2Client([
        'DescribeSecurityGroups' => describeTaskAndLoadBalancerGroups(),
        'DescribeSecurityGroupRules' => new Result(['SecurityGroupRules' => [
            [
                'SecurityGroupRuleId' => 'sgr-existing',
                'IsEgress' => false,
                'IpProtocol' => 'tcp',
                'FromPort' => 8000,
                'ToPort' => 8000,
                'ReferencedGroupInfo' => ['GroupId' => 'sg-lb789'],
            ],
        ]]),
    ], $captured);

    $step = new SyncTaskSecurityGroupStep();
    $step([]);

    expect(array_column($captured, 'name'))
        ->not->toContain('AuthorizeSecurityGroupIngress')
        ->not->toContain('RevokeSecurityGroupIngress');

    // The matching rule is already authorised, so the ingress reconcile records
    // nothing — otherwise every sync would surface phantom ingress drift.
    $ingressChanges = collect($step->changes())->filter(
        fn ($change): bool => str_contains((string) $change->attribute, 'ingress')
    );
    expect($ingressChanges)->toBeEmpty();
});

it('treats a manifest-specified task security group as custom-managed', function (): void {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'ecs' => ['security-group' => 'yolo-testing-my-app-ecs-task-security-group'],
    ]);

    $captured = [];

    bindMockEc2Client([
        'DescribeSecurityGroups' => describeTaskAndLoadBalancerGroups(),
    ], $captured);

    expect((new SyncTaskSecurityGroupStep())([]))->toBe(StepResult::CUSTOM_MANAGED);
    expect(array_column($captured, 'name'))->not->toContain('AuthorizeSecurityGroupIngress');
});

it('does not authorise during a dry-run', function (): void {
    $captured = [];

    bindMockEc2Client([
        'DescribeSecurityGroups' => describeTaskAndLoadBalancerGroups(),
        'DescribeSecurityGroupRules' => new Result(['SecurityGroupRules' => []]),
    ], $captured);

    (new SyncTaskSecurityGroupStep())(['dry-run' => true]);

    expect(array_column($captured, 'name'))->not->toContain('AuthorizeSecurityGroupIngress');
});

it('records a pending change on the plan pass when the rule is absent so the step survives the prune', function (): void {
    // Regression: the rule was written at create-time but recorded no Change on the
    // plan (dry-run) pass, so the runner's pending filter pruned the step before apply.
    // A task SG that exists without the rule (a create interrupted mid-flight) could
    // therefore never be self-healed by a later sync. The change must be recorded
    // regardless of --dry-run, mirroring AuthorisesTaskIngress.
    $captured = [];

    bindMockEc2Client([
        'DescribeSecurityGroups' => describeTaskAndLoadBalancerGroups(),
        'DescribeSecurityGroupRules' => new Result(['SecurityGroupRules' => []]),
    ], $captured);

    $step = new SyncTaskSecurityGroupStep();
    $step(['dry-run' => true]);

    expect($step->changes())->not->toBeEmpty();
});
