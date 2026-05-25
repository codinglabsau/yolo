<?php

use Codinglabs\Yolo\Audit\Audit;

/**
 * @param  array<string, string>  $tags  associative key => value
 */
function auditResource(string $arn, array $tags = []): array
{
    return [
        'ResourceARN' => $arn,
        'Tags' => collect($tags)->map(fn ($value, $key) => ['Key' => $key, 'Value' => $value])->values()->all(),
    ];
}

it('derives live app names from cluster ARNs by the yolo-{env}-{app} convention', function () {
    $apps = Audit::appsFromClusters([
        'arn:aws:ecs:ap-southeast-2:111:cluster/yolo-production-codinglabs',
        'arn:aws:ecs:ap-southeast-2:111:cluster/yolo-production-ghost',
        'arn:aws:ecs:ap-southeast-2:111:cluster/yolo-staging-codinglabs', // other environment
        'arn:aws:ecs:ap-southeast-2:111:cluster/default',                  // not a yolo cluster
        'arn:aws:ecs:ap-southeast-2:111:cluster/yolo-production',          // bare env, no app
    ], 'production');

    expect($apps)->toBe(['codinglabs', 'ghost']);
});

it('classifies resources as live, drift or unattributed', function () {
    $report = Audit::classify([
        auditResource('arn:aws:ecs:ap-southeast-2:111:service/yolo-production-codinglabs/web', ['yolo:app' => 'codinglabs', 'Name' => 'yolo-production-codinglabs-web']),
        auditResource('arn:aws:s3:::yolo-production-codinglabs-assets', ['yolo:app' => 'codinglabs', 'Name' => 'yolo-production-codinglabs-assets']),
        auditResource('arn:aws:ecr:ap-southeast-2:111:repository/yolo-production-ghost', ['yolo:app' => 'ghost', 'Name' => 'yolo-production-ghost']),
        auditResource('arn:aws:elasticloadbalancing:ap-southeast-2:111:loadbalancer/app/yolo-production/abc', ['Name' => 'yolo-production']),
    ], liveApps: ['codinglabs']);

    expect($report['liveCount'])->toBe(2)
        ->and($report['driftCount'])->toBe(1)
        ->and($report['unattributedCount'])->toBe(1);

    $byArn = collect($report['resources'])->keyBy('arn');

    // tagged for a live app
    expect($byArn['arn:aws:ecs:ap-southeast-2:111:service/yolo-production-codinglabs/web']['status'])->toBe('live');
    // tagged for an app with no live cluster
    expect($byArn['arn:aws:ecr:ap-southeast-2:111:repository/yolo-production-ghost']['status'])->toBe('drift');
    expect($byArn['arn:aws:ecr:ap-southeast-2:111:repository/yolo-production-ghost']['app'])->toBe('ghost');
    // no yolo:app — shared infra, never flagged as drift
    expect($byArn['arn:aws:elasticloadbalancing:ap-southeast-2:111:loadbalancer/app/yolo-production/abc']['status'])->toBe('unattributed');
    expect($byArn['arn:aws:elasticloadbalancing:ap-southeast-2:111:loadbalancer/app/yolo-production/abc']['app'])->toBeNull();
});

it('derives a readable type and name for each resource', function () {
    $report = Audit::classify([
        auditResource('arn:aws:ecs:ap-southeast-2:111:service/yolo-production-codinglabs/web', ['Name' => 'yolo-production-codinglabs-web']),
        auditResource('arn:aws:s3:::yolo-production-codinglabs-assets'), // no tags at all
    ], liveApps: []);

    $byArn = collect($report['resources'])->keyBy('arn');

    expect($byArn['arn:aws:ecs:ap-southeast-2:111:service/yolo-production-codinglabs/web']['type'])->toBe('ecs/service');
    expect($byArn['arn:aws:ecs:ap-southeast-2:111:service/yolo-production-codinglabs/web']['name'])->toBe('yolo-production-codinglabs-web');

    // no Name tag → falls back to the ARN's resource id; bare-id ARN type is just the service
    expect($byArn['arn:aws:s3:::yolo-production-codinglabs-assets']['type'])->toBe('s3');
    expect($byArn['arn:aws:s3:::yolo-production-codinglabs-assets']['name'])->toBe('yolo-production-codinglabs-assets');
});

it('returns zero counts and no resources for an empty inventory', function () {
    $report = Audit::classify([], liveApps: ['codinglabs']);

    expect($report['resources'])->toBe([])
        ->and($report['liveCount'])->toBe(0)
        ->and($report['driftCount'])->toBe(0)
        ->and($report['unattributedCount'])->toBe(0)
        ->and($report['liveApps'])->toBe(['codinglabs']);
});
