<?php

use Aws\Result;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\YoloObserver;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncYoloObserverPolicyStep;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

it('creates the env-shared observer policy when absent', function (): void {
    $captured = [];
    bindRoutedIamClient([
        'ListPolicies' => new Result(['Policies' => []]),
    ], $captured);

    expect((new SyncYoloObserverPolicyStep())([]))->toBe(StepResult::CREATED);

    $create = collect($captured)->firstWhere('name', 'CreatePolicy');
    expect($create)->not->toBeNull();
    expect($create['args']['PolicyName'])->toBe('yolo-testing-observer');
});

it('would-create the observer policy on a dry-run without writing', function (): void {
    $captured = [];
    bindRoutedIamClient([
        'ListPolicies' => new Result(['Policies' => []]),
    ], $captured);

    expect((new SyncYoloObserverPolicyStep())(['dry-run' => true]))->toBe(StepResult::WOULD_CREATE);
    expect(array_column($captured, 'name'))->not->toContain('CreatePolicy');
});

it('stays in sync without re-versioning when the live document already matches', function (): void {
    $captured = [];
    bindRoutedIamClient([
        'ListPolicies' => new Result(['Policies' => [[
            'PolicyName' => 'yolo-testing-observer',
            'Arn' => 'arn:aws:iam::111111111111:policy/yolo-testing-observer',
            'DefaultVersionId' => 'v1',
        ]]]),
        'GetPolicyVersion' => new Result(['PolicyVersion' => [
            'Document' => rawurlencode(json_encode((new YoloObserver())->document())),
        ]]),
        'ListPolicyTags' => new Result(['Tags' => [
            ['Key' => 'Name', 'Value' => 'yolo-testing-observer'],
            ['Key' => 'yolo:scope', 'Value' => 'env'],
            ['Key' => 'yolo:environment', 'Value' => 'testing'],
        ]]),
    ], $captured);

    expect((new SyncYoloObserverPolicyStep())([]))->toBe(StepResult::SYNCED);
    expect(array_column($captured, 'name'))->not->toContain('CreatePolicyVersion');
});
