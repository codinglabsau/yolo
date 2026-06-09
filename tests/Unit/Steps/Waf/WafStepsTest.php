<?php

use Aws\Result;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncWafWebAclStep;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncWafAssociationStep;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'waf' => true,
    ]);
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
