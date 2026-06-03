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

it('classifies resources as ok, drift or rogue', function () {
    $report = Audit::classify([
        auditResource('arn:aws:ecs:ap-southeast-2:111:service/yolo-production-codinglabs/web', ['yolo:app' => 'codinglabs', 'yolo:scope' => 'app', 'Name' => 'yolo-production-codinglabs-web']),
        auditResource('arn:aws:s3:::yolo-production-codinglabs-assets', ['yolo:app' => 'codinglabs', 'yolo:scope' => 'app', 'Name' => 'yolo-production-codinglabs-assets']),
        auditResource('arn:aws:ecr:ap-southeast-2:111:repository/yolo-production-ghost', ['yolo:app' => 'ghost', 'yolo:scope' => 'app', 'Name' => 'yolo-production-ghost']),
        // env-scope shared infra, stamped by sync — ok, not rogue
        auditResource('arn:aws:elasticloadbalancing:ap-southeast-2:111:loadbalancer/app/yolo-production/abc', ['yolo:scope' => 'env', 'Name' => 'yolo-production']),
        // alpha-era debris: no yolo:app, no yolo:scope — genuinely rogue
        auditResource('arn:aws:ssm:ap-southeast-2:111:parameter/yolo/production/background-work-strategy', ['Name' => 'yolo/production/background-work-strategy']),
    ], liveApps: ['codinglabs']);

    expect($report['okCount'])->toBe(3)
        ->and($report['driftCount'])->toBe(1)
        ->and($report['rogueCount'])->toBe(1);

    $byArn = collect($report['resources'])->keyBy('arn');

    // tagged for a live app
    expect($byArn['arn:aws:ecs:ap-southeast-2:111:service/yolo-production-codinglabs/web']['status'])->toBe('ok');
    // tagged for an app with no live cluster
    expect($byArn['arn:aws:ecr:ap-southeast-2:111:repository/yolo-production-ghost']['status'])->toBe('drift');
    expect($byArn['arn:aws:ecr:ap-southeast-2:111:repository/yolo-production-ghost']['app'])->toBe('ghost');
    // declared env-shared infra — ok, never flagged as rogue
    expect($byArn['arn:aws:elasticloadbalancing:ap-southeast-2:111:loadbalancer/app/yolo-production/abc']['status'])->toBe('ok');
    expect($byArn['arn:aws:elasticloadbalancing:ap-southeast-2:111:loadbalancer/app/yolo-production/abc']['app'])->toBeNull();
    // no YOLO ownership marker — rogue
    expect($byArn['arn:aws:ssm:ap-southeast-2:111:parameter/yolo/production/background-work-strategy']['status'])->toBe('rogue');
});

it('classifies a YOLO-owned resource of an unmanaged service as orphan', function () {
    $report = Audit::classify([
        // The DynamoDB sessions table left behind after DynamoDB support was
        // removed: still tagged for a LIVE app, so the ownership test alone reads
        // it as ok — but YOLO has no DynamoDB resource any more, so it's orphan.
        auditResource('arn:aws:dynamodb:ap-southeast-2:111:table/yolo-production-codinglabs-sessions', ['yolo:app' => 'codinglabs', 'yolo:scope' => 'app', 'Name' => 'yolo-production-codinglabs-sessions']),
        // A managed-service resource for the same live app stays ok — orphan must
        // not over-fire on services YOLO still provisions.
        auditResource('arn:aws:ecs:ap-southeast-2:111:service/yolo-production-codinglabs/web', ['yolo:app' => 'codinglabs', 'yolo:scope' => 'app', 'Name' => 'yolo-production-codinglabs-web']),
        // An unmanaged service with NO ownership marker is rogue, not orphan —
        // orphan is reserved for resources YOLO genuinely owns.
        auditResource('arn:aws:dynamodb:ap-southeast-2:111:table/hand-rolled', ['Name' => 'hand-rolled']),
    ], liveApps: ['codinglabs']);

    expect($report['orphanCount'])->toBe(1)
        ->and($report['okCount'])->toBe(1)
        ->and($report['rogueCount'])->toBe(1)
        ->and($report['driftCount'])->toBe(0);

    $byArn = collect($report['resources'])->keyBy('arn');

    expect($byArn['arn:aws:dynamodb:ap-southeast-2:111:table/yolo-production-codinglabs-sessions']['status'])->toBe('orphan')
        ->and($byArn['arn:aws:ecs:ap-southeast-2:111:service/yolo-production-codinglabs/web']['status'])->toBe('ok')
        ->and($byArn['arn:aws:dynamodb:ap-southeast-2:111:table/hand-rolled']['status'])->toBe('rogue');
});

it('flags an orphan even when the owning app is no longer live (orphan precedes drift)', function () {
    $report = Audit::classify([
        auditResource('arn:aws:dynamodb:ap-southeast-2:111:table/yolo-production-ghost-sessions', ['yolo:app' => 'ghost', 'yolo:scope' => 'app']),
    ], liveApps: ['codinglabs']);

    expect($report['orphanCount'])->toBe(1)
        ->and($report['driftCount'])->toBe(0);
});

it('treats a yolo:app pointing at a dead app as drift even when yolo:scope=app is stamped', function () {
    $report = Audit::classify([
        auditResource('arn:aws:ecr:ap-southeast-2:111:repository/yolo-production-ghost', ['yolo:app' => 'ghost', 'yolo:scope' => 'app']),
    ], liveApps: ['codinglabs']);

    expect($report['driftCount'])->toBe(1)
        ->and($report['okCount'])->toBe(0)
        ->and($report['rogueCount'])->toBe(0);
});

it('falls back to inference for resources synced before the yolo:scope rollout', function () {
    // An app-scope resource pre-rollout still has yolo:app and resolves as ok.
    $appReport = Audit::classify([
        auditResource('arn:aws:ecs:ap-southeast-2:111:service/yolo-production-codinglabs/web', ['yolo:app' => 'codinglabs']),
    ], liveApps: ['codinglabs']);

    expect($appReport['okCount'])->toBe(1)
        ->and($appReport['rogueCount'])->toBe(0);

    // A pre-rollout env-shared resource (no yolo:app, no yolo:scope) reads as
    // rogue until sync backfills the scope tag. That's the cost of using a
    // positive signal — and it's preferable to false-greening genuine debris.
    $envReport = Audit::classify([
        auditResource('arn:aws:elasticloadbalancing:ap-southeast-2:111:loadbalancer/app/yolo-production/abc', ['Name' => 'yolo-production']),
    ], liveApps: []);

    expect($envReport['okCount'])->toBe(0)
        ->and($envReport['rogueCount'])->toBe(1);
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
        ['scope' => 'env', 'status' => 'rogue', 'app' => null, 'name' => 'vpc'],
        ['scope' => 'account', 'status' => 'rogue', 'app' => null, 'name' => 'oidc'],
        ['scope' => 'app', 'status' => 'drift', 'app' => 'ghost', 'name' => 'repo'],
        ['scope' => 'env', 'status' => 'rogue', 'app' => null, 'name' => 'alb'],
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
        ->and($report['orphanCount'])->toBe(0)
        ->and($report['rogueCount'])->toBe(0)
        ->and($report['liveApps'])->toBe(['codinglabs']);
});
