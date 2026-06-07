<?php

use Aws\Result;
use Codinglabs\Yolo\Audit\Audit;
use Codinglabs\Yolo\Aws\ResourceGroupsTaggingApi;

/**
 * A tagged-resource mapping in the Tagging API's GetResources wire shape.
 *
 * @param  array<string, string>  $tags  associative key => value
 */
function rgtMapping(string $arn, array $tags = []): array
{
    return [
        'ResourceARN' => $arn,
        'Tags' => collect($tags)->map(fn ($value, $key) => ['Key' => $key, 'Value' => $value])->values()->all(),
    ];
}

/**
 * @param  array<int, array<string, mixed>>  $mappings
 */
function rgtPage(array $mappings, string $token = ''): Result
{
    return new Result(['ResourceTagMappingList' => $mappings, 'PaginationToken' => $token]);
}

it('merges the regional and us-east-1 passes so global resources appear', function () {
    bindMockResourceGroupsTaggingApiClient('resourceGroupsTaggingApi', [
        rgtPage([rgtMapping('arn:aws:ecs:ap-southeast-2:111:service/yolo-production-codinglabs/web')]),
    ]);
    bindMockResourceGroupsTaggingApiClient('resourceGroupsTaggingApiGlobal', [
        rgtPage([rgtMapping('arn:aws:iam::111:role/yolo-production-codinglabs-task-role')]),
    ]);

    $arns = collect(ResourceGroupsTaggingApi::getResources([
        ['Key' => 'yolo:environment', 'Values' => ['production']],
    ]))->pluck('ResourceARN');

    expect($arns)->toContain('arn:aws:ecs:ap-southeast-2:111:service/yolo-production-codinglabs/web')
        ->and($arns)->toContain('arn:aws:iam::111:role/yolo-production-codinglabs-task-role');
});

it('dedupes by ARN when both passes return the same resource (us-east-1 environment)', function () {
    $role = rgtMapping('arn:aws:iam::111:role/yolo-production-codinglabs-task-role', ['yolo:scope' => 'app']);

    bindMockResourceGroupsTaggingApiClient('resourceGroupsTaggingApi', [rgtPage([$role])]);
    bindMockResourceGroupsTaggingApiClient('resourceGroupsTaggingApiGlobal', [rgtPage([$role])]);

    $resources = ResourceGroupsTaggingApi::getResources([
        ['Key' => 'yolo:environment', 'Values' => ['production']],
    ]);

    expect($resources)->toHaveCount(1);
});

it('follows the PaginationToken to the end on each pass', function () {
    bindMockResourceGroupsTaggingApiClient('resourceGroupsTaggingApi', [
        rgtPage([rgtMapping('arn:aws:s3:::yolo-production-a')], token: 'next'),
        rgtPage([rgtMapping('arn:aws:s3:::yolo-production-b')]),
    ]);
    bindMockResourceGroupsTaggingApiClient('resourceGroupsTaggingApiGlobal', [rgtPage([])]);

    $arns = collect(ResourceGroupsTaggingApi::getResources([
        ['Key' => 'yolo:environment', 'Values' => ['production']],
    ]))->pluck('ResourceARN');

    expect($arns->all())->toBe([
        'arn:aws:s3:::yolo-production-a',
        'arn:aws:s3:::yolo-production-b',
    ]);
});

it('surfaces global resources through classification — IAM role ok, removed-service global resource unexpected', function () {
    bindMockResourceGroupsTaggingApiClient('resourceGroupsTaggingApi', [rgtPage([])]);
    bindMockResourceGroupsTaggingApiClient('resourceGroupsTaggingApiGlobal', [
        rgtPage([
            // An IAM role tagged for a live app — now visible to audit, classifies ok.
            rgtMapping('arn:aws:iam::111:role/yolo-production-codinglabs-task-role', [
                'yolo:app' => 'codinglabs', 'yolo:scope' => 'app', 'Name' => 'yolo-production-codinglabs-task-role',
            ]),
            // A YOLO-owned global resource of a service YOLO no longer provisions
            // (a us-east-1 WAFv2 web ACL) — the global analogue of the DynamoDB
            // sessions orphan: owned by a live app, but no Resources/ class, so a
            // sync would never recreate it → unexpected, service no longer provisioned.
            rgtMapping('arn:aws:wafv2:us-east-1:111:global/webacl/yolo-production-codinglabs/abc', [
                'yolo:app' => 'codinglabs', 'yolo:scope' => 'app', 'Name' => 'yolo-production-codinglabs',
            ]),
        ]),
    ]);

    $tagged = ResourceGroupsTaggingApi::getResources([
        ['Key' => 'yolo:environment', 'Values' => ['production']],
    ]);
    $report = Audit::classify($tagged, liveApps: ['codinglabs']);

    $byArn = collect($report['resources'])->keyBy('arn');

    expect($byArn['arn:aws:iam::111:role/yolo-production-codinglabs-task-role']['status'])->toBe('ok')
        ->and($byArn['arn:aws:wafv2:us-east-1:111:global/webacl/yolo-production-codinglabs/abc']['status'])->toBe('unexpected')
        ->and($byArn['arn:aws:wafv2:us-east-1:111:global/webacl/yolo-production-codinglabs/abc']['reason'])->toBe(Audit::REASON_UNMANAGED_SERVICE);
});
