<?php

use Aws\Result;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncWafWebAclStep;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncWafAssociationStep;

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);
});

const WAF_WEBACL_ARN = 'arn:aws:wafv2:ap-southeast-2:111:regional/webacl/yolo-testing-waf/acl-id';

function wafLoadBalancerResult(): Result
{
    return new Result(['LoadBalancers' => [[
        'LoadBalancerName' => 'yolo-testing',
        'LoadBalancerArn' => 'arn:aws:elasticloadbalancing:ap-southeast-2:111:loadbalancer/app/yolo-testing/abc',
    ]]]);
}

it('honours the reconciler contract for the web ACL', function (): void {
    // Resolve the desired rules once (reuses the WafV2 fixtures from WebAclTest).
    $desired = desiredWafRules();

    assertSyncStepReconciles(
        makeStep: fn (): SyncWafWebAclStep => new SyncWafWebAclStep(),
        bindInSync: function (array &$captured) use ($desired): void {
            bindRoutedWafV2Client([
                'ListIPSets' => wafIpSetsResult(),
                'ListWebACLs' => wafWebAclsResult(),
                'GetWebACL' => liveWebAclResult($desired),
                'ListTagsForResource' => wafWebAclTagsResult(),
            ], $captured);
        },
        bindDrifted: function (array &$captured) use ($desired): void {
            bindRoutedWafV2Client([
                'ListIPSets' => wafIpSetsResult(),
                'ListWebACLs' => wafWebAclsResult(),
                'GetWebACL' => liveWebAclResult($desired, defaultAction: ['Block' => []]),
                'ListTagsForResource' => wafWebAclTagsResult(),
                'UpdateWebACL' => new Result(['NextLockToken' => 'lt-next']),
            ], $captured);
        },
        writeCommand: 'UpdateWebACL',
    );
});

it('honours the reconciler contract for the ALB association', function (): void {
    assertSyncStepReconciles(
        makeStep: fn (): SyncWafAssociationStep => new SyncWafAssociationStep(),
        bindInSync: function (array &$captured): void {
            bindRoutedElbV2Client(['DescribeLoadBalancers' => wafLoadBalancerResult()], $captured);
            bindRoutedWafV2Client([
                'ListWebACLs' => wafWebAclsResult(),
                'GetWebACLForResource' => new Result(['WebACL' => ['ARN' => WAF_WEBACL_ARN]]),
            ], $captured);
        },
        bindDrifted: function (array &$captured): void {
            bindRoutedElbV2Client(['DescribeLoadBalancers' => wafLoadBalancerResult()], $captured);
            bindRoutedWafV2Client([
                'ListWebACLs' => wafWebAclsResult(),
                'GetWebACLForResource' => new Result([]),
                'AssociateWebACL' => new Result([]),
            ], $captured);
        },
        writeCommand: 'AssociateWebACL',
    );
});

it('points the load balancer at the web ACL when unassociated', function (): void {
    $captured = [];
    bindRoutedElbV2Client(['DescribeLoadBalancers' => wafLoadBalancerResult()], $captured);
    bindRoutedWafV2Client([
        'ListWebACLs' => wafWebAclsResult(),
        'GetWebACLForResource' => new Result([]),
        'AssociateWebACL' => new Result([]),
    ], $captured);

    (new SyncWafAssociationStep())([]);

    $associate = collect($captured)->firstWhere('name', 'AssociateWebACL');

    expect($associate['args']['WebACLArn'])->toBe(WAF_WEBACL_ARN)
        ->and($associate['args']['ResourceArn'])->toBe('arn:aws:elasticloadbalancing:ap-southeast-2:111:loadbalancer/app/yolo-testing/abc');
});

it('reports a pending association without touching AWS when the web ACL does not exist yet', function (): void {
    // First-ever sync, plan pass: the web ACL's create step also ran dry-run, so it
    // isn't provisioned. The association step must not try to resolve its ARN (which
    // would throw — WAFv2 ARNs aren't computable offline); it reports a pending
    // association instead. Regression for the plan-pass crash on a fresh environment.
    $captured = [];
    bindRoutedWafV2Client(['ListWebACLs' => new Result(['WebACLs' => []])], $captured);

    $step = new SyncWafAssociationStep();

    expect($step(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC);
    expect($step->changes())->not->toBeEmpty();
    expect(array_column($captured, 'name'))
        ->not->toContain('GetWebACLForResource')
        ->not->toContain('AssociateWebACL');
});
