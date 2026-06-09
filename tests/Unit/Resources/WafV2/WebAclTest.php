<?php

use Aws\Result;
use Codinglabs\Yolo\Resources\WafV2\WebAcl;

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);
});

it('is named for the environment scope', function (): void {
    expect((new WebAcl())->name())->toBe('yolo-testing-waf');
});

it('creates the web ACL with an allow default action and the full rule skeleton', function (): void {
    $captured = [];
    bindRoutedWafV2Client([
        'ListIPSets' => wafIpSetsResult(),
        'CreateWebACL' => new Result(['Summary' => ['ARN' => 'arn:aws:wafv2:ap-southeast-2:111:regional/webacl/yolo-testing-waf/acl-id']]),
    ], $captured);

    (new WebAcl())->create();

    $create = collect($captured)->firstWhere('name', 'CreateWebACL');

    expect($create['args']['Name'])->toBe('yolo-testing-waf')
        ->and($create['args']['Scope'])->toBe('REGIONAL')
        ->and($create['args']['DefaultAction'])->toBe(['Allow' => []]);

    $ruleNames = array_column($create['args']['Rules'], 'Name');

    expect($ruleNames)->toBe([
        'yolo-allow-ips',
        'yolo-block-ips',
        'AWS-AWSManagedRulesAmazonIpReputationList',
        'AWS-AWSManagedRulesKnownBadInputsRuleSet',
        'AWS-AWSManagedRulesCommonRuleSet',
        'AWS-AWSManagedRulesSQLiRuleSet',
        'AWS-AWSManagedRulesPHPRuleSet',
        'yolo-rate-limit',
    ]);

    // The broad content groups ship in Count; the low-false-positive ones block.
    $byName = collect($create['args']['Rules'])->keyBy('Name');
    expect($byName['AWS-AWSManagedRulesCommonRuleSet']['OverrideAction'])->toBe(['Count' => []])
        ->and($byName['AWS-AWSManagedRulesSQLiRuleSet']['OverrideAction'])->toBe(['Count' => []])
        ->and($byName['AWS-AWSManagedRulesPHPRuleSet']['OverrideAction'])->toBe(['Count' => []])
        ->and($byName['AWS-AWSManagedRulesAmazonIpReputationList']['OverrideAction'])->toBe(['None' => []])
        ->and($byName['AWS-AWSManagedRulesKnownBadInputsRuleSet']['OverrideAction'])->toBe(['None' => []]);

    // Managed groups are referenced unversioned so they track the latest signatures.
    expect($byName['AWS-AWSManagedRulesCommonRuleSet']['Statement']['ManagedRuleGroupStatement'])
        ->not->toHaveKey('Version');

    expect($create['args']['Tags'])->toContain(['Key' => 'yolo:scope', 'Value' => 'env']);
});

it('reports exists from the live web ACL list', function (): void {
    $captured = [];
    bindRoutedWafV2Client(['ListWebACLs' => wafWebAclsResult()], $captured);
    expect((new WebAcl())->exists())->toBeTrue();

    bindRoutedWafV2Client(['ListWebACLs' => new Result(['WebACLs' => []])], $captured);
    expect((new WebAcl())->exists())->toBeFalse();
});

it('reports no change when the live policy matches', function (): void {
    $captured = [];
    bindRoutedWafV2Client([
        'ListIPSets' => wafIpSetsResult(),
        'ListWebACLs' => wafWebAclsResult(),
        'GetWebACL' => liveWebAclResult(desiredWafRules()),
    ], $captured);

    expect((new WebAcl())->synchroniseConfiguration())->toBe([]);
    expect(array_column($captured, 'name'))->not->toContain('UpdateWebACL');
});

it('detects a default-action drift and rewrites the ACL', function (): void {
    $captured = [];
    bindRoutedWafV2Client([
        'ListIPSets' => wafIpSetsResult(),
        'ListWebACLs' => wafWebAclsResult(),
        'GetWebACL' => liveWebAclResult(desiredWafRules(), defaultAction: ['Block' => []]),
        'UpdateWebACL' => new Result(['NextLockToken' => 'lt-next']),
    ], $captured);

    $changes = (new WebAcl())->synchroniseConfiguration();

    expect(collect($changes)->pluck('attribute'))->toContain('default-action');
    expect(array_column($captured, 'name'))->toContain('UpdateWebACL');
});

it('detects a missing managed rule and rewrites the ACL', function (): void {
    $captured = [];
    $partial = collect(desiredWafRules())
        ->reject(fn (array $rule): bool => $rule['Name'] === 'AWS-AWSManagedRulesSQLiRuleSet')
        ->values()
        ->all();

    bindRoutedWafV2Client([
        'ListIPSets' => wafIpSetsResult(),
        'ListWebACLs' => wafWebAclsResult(),
        'GetWebACL' => liveWebAclResult($partial),
        'UpdateWebACL' => new Result(['NextLockToken' => 'lt-next']),
    ], $captured);

    $changes = (new WebAcl())->synchroniseConfiguration();

    expect(collect($changes)->pluck('attribute'))->toContain('rules');
    expect(array_column($captured, 'name'))->toContain('UpdateWebACL');
});

it('computes the diff without writing under apply:false', function (): void {
    $captured = [];
    bindRoutedWafV2Client([
        'ListIPSets' => wafIpSetsResult(),
        'ListWebACLs' => wafWebAclsResult(),
        'GetWebACL' => liveWebAclResult(desiredWafRules(), defaultAction: ['Block' => []]),
    ], $captured);

    expect((new WebAcl())->synchroniseConfiguration(apply: false))->not->toBeEmpty();
    expect(array_column($captured, 'name'))->not->toContain('UpdateWebACL');
});

it('preserves a hand-added rule through a reconciling update', function (): void {
    $humanRule = [
        'Name' => 'operator-geo-block',
        'Priority' => 100,
        'Action' => ['Block' => []],
        'Statement' => ['GeoMatchStatement' => ['CountryCodes' => ['CN']]],
        'VisibilityConfig' => ['SampledRequestsEnabled' => true, 'CloudWatchMetricsEnabled' => true, 'MetricName' => 'operator-geo-block'],
    ];

    $captured = [];
    bindRoutedWafV2Client([
        'ListIPSets' => wafIpSetsResult(),
        'ListWebACLs' => wafWebAclsResult(),
        // Drift (Block default) forces the update; the human rule rides alongside.
        'GetWebACL' => liveWebAclResult([$humanRule, ...desiredWafRules()], defaultAction: ['Block' => []]),
        'UpdateWebACL' => new Result(['NextLockToken' => 'lt-next']),
    ], $captured);

    (new WebAcl())->synchroniseConfiguration();

    $update = collect($captured)->firstWhere('name', 'UpdateWebACL');
    $writtenNames = array_column($update['args']['Rules'], 'Name');

    expect($writtenNames)->toContain('operator-geo-block')
        ->and($writtenNames)->toContain('yolo-allow-ips')
        ->and($writtenNames)->toContain('AWS-AWSManagedRulesSQLiRuleSet')
        ->and($update['args']['LockToken'])->toBe('lt-acl');
});
