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

it('classifies resources as ok, drift or unattributed', function () {
    $report = Audit::classify([
        auditResource('arn:aws:ecs:ap-southeast-2:111:service/yolo-production-codinglabs/web', ['yolo:app' => 'codinglabs', 'Name' => 'yolo-production-codinglabs-web']),
        auditResource('arn:aws:s3:::yolo-production-codinglabs-assets', ['yolo:app' => 'codinglabs', 'Name' => 'yolo-production-codinglabs-assets']),
        auditResource('arn:aws:ecr:ap-southeast-2:111:repository/yolo-production-ghost', ['yolo:app' => 'ghost', 'Name' => 'yolo-production-ghost']),
        auditResource('arn:aws:elasticloadbalancing:ap-southeast-2:111:loadbalancer/app/yolo-production/abc', ['Name' => 'yolo-production']),
    ], liveApps: ['codinglabs']);

    expect($report['okCount'])->toBe(2)
        ->and($report['driftCount'])->toBe(1)
        ->and($report['unattributedCount'])->toBe(1);

    $byArn = collect($report['resources'])->keyBy('arn');

    // tagged for a live app
    expect($byArn['arn:aws:ecs:ap-southeast-2:111:service/yolo-production-codinglabs/web']['status'])->toBe('ok');
    // tagged for an app with no live cluster
    expect($byArn['arn:aws:ecr:ap-southeast-2:111:repository/yolo-production-ghost']['status'])->toBe('drift');
    expect($byArn['arn:aws:ecr:ap-southeast-2:111:repository/yolo-production-ghost']['app'])->toBe('ghost');
    // no yolo:app — shared infra, never flagged as drift
    expect($byArn['arn:aws:elasticloadbalancing:ap-southeast-2:111:loadbalancer/app/yolo-production/abc']['status'])->toBe('unattributed');
    expect($byArn['arn:aws:elasticloadbalancing:ap-southeast-2:111:loadbalancer/app/yolo-production/abc']['app'])->toBeNull();
});

it('assigns an ownership scope to each resource', function () {
    $report = Audit::classify([
        // yolo:app present → app scope (even when drift, an orphaned app resource)
        auditResource('arn:aws:ecr:ap-southeast-2:111:repository/yolo-production-ghost', ['yolo:app' => 'ghost']),
        // no yolo:app, env-shared infra → env scope
        auditResource('arn:aws:elasticloadbalancing:ap-southeast-2:111:loadbalancer/app/yolo-production/abc'),
        // the GitHub OIDC provider is account-global → account scope
        auditResource('arn:aws:iam::111:oidc-provider/token.actions.githubusercontent.com'),
        // an explicit yolo:scope tag wins over inference
        auditResource('arn:aws:s3:::some-bucket', ['yolo:scope' => 'env']),
    ], liveApps: []);

    $byArn = collect($report['resources'])->keyBy('arn');

    expect($byArn['arn:aws:ecr:ap-southeast-2:111:repository/yolo-production-ghost']['scope'])->toBe('app')
        ->and($byArn['arn:aws:elasticloadbalancing:ap-southeast-2:111:loadbalancer/app/yolo-production/abc']['scope'])->toBe('env')
        ->and($byArn['arn:aws:iam::111:oidc-provider/token.actions.githubusercontent.com']['scope'])->toBe('account')
        ->and($byArn['arn:aws:s3:::some-bucket']['scope'])->toBe('env');
});

it('orders rows by scope (account → env → app), then drift-first within a scope', function () {
    $rows = [
        ['scope' => 'app', 'status' => 'ok', 'app' => 'codinglabs', 'name' => 'web'],
        ['scope' => 'env', 'status' => 'unattributed', 'app' => null, 'name' => 'vpc'],
        ['scope' => 'account', 'status' => 'unattributed', 'app' => null, 'name' => 'oidc'],
        ['scope' => 'app', 'status' => 'drift', 'app' => 'ghost', 'name' => 'repo'],
        ['scope' => 'env', 'status' => 'unattributed', 'app' => null, 'name' => 'alb'],
    ];

    $ordered = collect($rows)->sortBy(fn (array $resource) => Audit::orderKey($resource))->values();

    expect($ordered->pluck('name')->all())->toBe(['oidc', 'alb', 'vpc', 'repo', 'web']);
    // account first, then env (alb before vpc by name), then app (drift before ok)
    expect($ordered->pluck('scope')->all())->toBe(['account', 'env', 'env', 'app', 'app']);
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
        ->and($report['okCount'])->toBe(0)
        ->and($report['driftCount'])->toBe(0)
        ->and($report['unattributedCount'])->toBe(0)
        ->and($report['liveApps'])->toBe(['codinglabs']);
});
