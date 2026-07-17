<?php

use Aws\Result;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\WafV2\AllowIpSet;
use Codinglabs\Yolo\Resources\WafV2\BlockIpSet;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncWafAllowIpSetStep;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncWafBlockIpSetStep;

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);
});

function allowIpSetListResult(): Result
{
    return new Result(['IPSets' => [
        ['Name' => 'yolo-testing-waf-allow', 'Id' => 'allow-id', 'LockToken' => 'lt', 'ARN' => 'arn:aws:wafv2:ap-southeast-2:111:regional/ipset/yolo-testing-waf-allow/allow-id'],
    ]]);
}

function allowIpSetTagsResult(): Result
{
    return new Result(['TagInfoForResource' => ['TagList' => [
        ['Key' => 'Name', 'Value' => 'yolo-testing-waf-allow'],
        ['Key' => 'yolo:scope', 'Value' => 'env'],
        ['Key' => 'yolo:environment', 'Value' => 'testing'],
    ]]]);
}

it('names the allow and block sets for the environment scope', function (): void {
    expect((new AllowIpSet())->name())->toBe('yolo-testing-waf-allow')
        ->and((new BlockIpSet())->name())->toBe('yolo-testing-waf-block');
});

it('creates an IP set seeded empty', function (): void {
    $captured = [];
    bindRoutedWafV2Client(['CreateIPSet' => new Result([])], $captured);

    (new AllowIpSet())->create();

    $create = collect($captured)->firstWhere('name', 'CreateIPSet');

    expect($create['args']['Name'])->toBe('yolo-testing-waf-allow')
        ->and($create['args']['Scope'])->toBe('REGIONAL')
        ->and($create['args']['IPAddressVersion'])->toBe('IPV4')
        ->and($create['args']['Addresses'])->toBe([])
        ->and($create['args']['Tags'])->toContain(['Key' => 'yolo:scope', 'Value' => 'env']);
});

it('creates the set when it is absent', function (): void {
    $captured = [];
    bindRoutedWafV2Client([
        'ListIPSets' => new Result(['IPSets' => []]),
        'CreateIPSet' => new Result([]),
    ], $captured);

    expect((new SyncWafAllowIpSetStep())([]))->toBe(StepResult::CREATED);
    expect(array_column($captured, 'name'))->toContain('CreateIPSet');
});

it('uses descriptions WAFv2 will accept', function (): void {
    // WAFv2's description field is regex-constrained server-side (no em-dashes,
    // parentheses, etc.). The SDK doesn't validate it client-side and MockHandler
    // can't, so a bad description only fails against real AWS — guard the wire value.
    $pattern = '/^[\w+=:#@\/\-,.][\w+=:#@\/\-,.\s]+[\w+=:#@\/\-,.]$/';

    foreach ([new AllowIpSet(), new BlockIpSet()] as $ipSet) {
        $captured = [];
        bindRoutedWafV2Client(['CreateIPSet' => new Result([])], $captured);
        $ipSet->create();

        expect(collect($captured)->firstWhere('name', 'CreateIPSet')['args']['Description'])->toMatch($pattern);
    }
});

it('creates the block set when it is absent', function (): void {
    $captured = [];
    bindRoutedWafV2Client([
        'ListIPSets' => new Result(['IPSets' => []]),
        'CreateIPSet' => new Result([]),
    ], $captured);

    expect((new SyncWafBlockIpSetStep())([]))->toBe(StepResult::CREATED);

    $create = collect($captured)->firstWhere('name', 'CreateIPSet');
    expect($create['args']['Name'])->toBe('yolo-testing-waf-block');
});

it('never reconciles IP set contents on an existing set — only tags', function (): void {
    $captured = [];
    bindRoutedWafV2Client([
        'ListIPSets' => allowIpSetListResult(),
        'ListTagsForResource' => allowIpSetTagsResult(),
    ], $captured);

    expect((new SyncWafAllowIpSetStep())([]))->toBe(StepResult::SYNCED);

    // The high-churn contents are create-only: a sync over an existing set must
    // never rewrite its addresses, so an operator's mid-incident edits survive.
    expect(array_column($captured, 'name'))
        ->not->toContain('UpdateIPSet')
        ->not->toContain('CreateIPSet');
});

it('honours the reconciler contract (tag drift) for the IP set step', function (): void {
    // Contents are create-only, so the IP set's only reconcile axis is its tags:
    // in-sync ⇒ SYNCED, no write; a missing tag ⇒ WOULD_SYNC on the plan, TagResource on apply.
    assertSyncStepReconciles(
        makeStep: fn (): SyncWafAllowIpSetStep => new SyncWafAllowIpSetStep(),
        bindInSync: function (array &$captured): void {
            bindRoutedWafV2Client([
                'ListIPSets' => allowIpSetListResult(),
                'ListTagsForResource' => allowIpSetTagsResult(),
            ], $captured);
        },
        bindDrifted: function (array &$captured): void {
            bindRoutedWafV2Client([
                'ListIPSets' => allowIpSetListResult(),
                'ListTagsForResource' => new Result(['TagInfoForResource' => ['TagList' => [
                    ['Key' => 'Name', 'Value' => 'yolo-testing-waf-allow'],
                    // Owned (the scope marker is present) — the drift is the
                    // missing yolo:environment baseline, not a foreign IP set.
                    ['Key' => 'yolo:scope', 'Value' => 'env'],
                ]]]),
                'TagResource' => new Result([]),
            ], $captured);
        },
        writeCommand: 'TagResource',
    );
});
