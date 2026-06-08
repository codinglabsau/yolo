<?php

use Codinglabs\Yolo\Audit\Audit;

/**
 * @param  array<string, string>  $tags  associative key => value
 */
function auditResource(string $arn, array $tags = []): array
{
    return [
        'ResourceARN' => $arn,
        'Tags' => collect($tags)->map(fn ($value, $key): array => ['Key' => $key, 'Value' => $value])->values()->all(),
    ];
}

it('derives live app names from cluster ARNs by the yolo-{env}-{app} convention', function (): void {
    $apps = Audit::appsFromClusters([
        'arn:aws:ecs:ap-southeast-2:111:cluster/yolo-production-codinglabs',
        'arn:aws:ecs:ap-southeast-2:111:cluster/yolo-production-ghost',
        'arn:aws:ecs:ap-southeast-2:111:cluster/yolo-staging-codinglabs', // other environment
        'arn:aws:ecs:ap-southeast-2:111:cluster/default',                  // not a yolo cluster
        'arn:aws:ecs:ap-southeast-2:111:cluster/yolo-production',          // bare env, no app
    ], 'production');

    expect($apps)->toBe(['codinglabs', 'ghost']);
});

it('classifies resources as ok or unexpected with a reason', function (): void {
    $report = Audit::classify([
        auditResource('arn:aws:ecs:ap-southeast-2:111:service/yolo-production-codinglabs/web', ['yolo:app' => 'codinglabs', 'yolo:scope' => 'app', 'Name' => 'yolo-production-codinglabs-web']),
        auditResource('arn:aws:s3:::yolo-production-codinglabs-assets', ['yolo:app' => 'codinglabs', 'yolo:scope' => 'app', 'Name' => 'yolo-production-codinglabs-assets']),
        auditResource('arn:aws:ecr:ap-southeast-2:111:repository/yolo-production-ghost', ['yolo:app' => 'ghost', 'yolo:scope' => 'app', 'Name' => 'yolo-production-ghost']),
        // env-scope shared infra, stamped by sync — ok
        auditResource('arn:aws:elasticloadbalancing:ap-southeast-2:111:loadbalancer/app/yolo-production/abc', ['yolo:scope' => 'env', 'Name' => 'yolo-production']),
        // alpha-era debris: no yolo:app, no yolo:scope
        auditResource('arn:aws:ssm:ap-southeast-2:111:parameter/yolo/production/background-work-strategy', ['Name' => 'yolo/production/background-work-strategy']),
    ], liveApps: ['codinglabs']);

    expect($report['okCount'])->toBe(3)
        ->and($report['unexpectedCount'])->toBe(2);

    $byArn = collect($report['resources'])->keyBy('arn');

    // tagged for a live app — ok, no reason
    expect($byArn['arn:aws:ecs:ap-southeast-2:111:service/yolo-production-codinglabs/web']['status'])->toBe('ok')
        ->and($byArn['arn:aws:ecs:ap-southeast-2:111:service/yolo-production-codinglabs/web']['reason'])->toBeNull();
    // tagged for an app with no live cluster — unexpected, app cluster gone
    expect($byArn['arn:aws:ecr:ap-southeast-2:111:repository/yolo-production-ghost']['status'])->toBe('unexpected')
        ->and($byArn['arn:aws:ecr:ap-southeast-2:111:repository/yolo-production-ghost']['reason'])->toBe(Audit::REASON_DEAD_APP)
        ->and($byArn['arn:aws:ecr:ap-southeast-2:111:repository/yolo-production-ghost']['app'])->toBe('ghost');
    // declared env-shared infra — ok
    expect($byArn['arn:aws:elasticloadbalancing:ap-southeast-2:111:loadbalancer/app/yolo-production/abc']['status'])->toBe('ok')
        ->and($byArn['arn:aws:elasticloadbalancing:ap-southeast-2:111:loadbalancer/app/yolo-production/abc']['app'])->toBeNull();
    // no YOLO ownership marker — unexpected, no ownership tag
    expect($byArn['arn:aws:ssm:ap-southeast-2:111:parameter/yolo/production/background-work-strategy']['status'])->toBe('unexpected')
        ->and($byArn['arn:aws:ssm:ap-southeast-2:111:parameter/yolo/production/background-work-strategy']['reason'])->toBe(Audit::REASON_NO_OWNER);
});

it('flags a YOLO-owned resource of an unmanaged service as unexpected', function (): void {
    $report = Audit::classify([
        // The DynamoDB sessions table left behind after DynamoDB support was
        // removed: still tagged for a LIVE app, so the ownership test alone would
        // read it ok — but YOLO has no DynamoDB resource any more.
        auditResource('arn:aws:dynamodb:ap-southeast-2:111:table/yolo-production-codinglabs-sessions', ['yolo:app' => 'codinglabs', 'yolo:scope' => 'app', 'Name' => 'yolo-production-codinglabs-sessions']),
        // A managed-service resource for the same live app stays ok — the service
        // check must not over-fire on services YOLO still provisions.
        auditResource('arn:aws:ecs:ap-southeast-2:111:service/yolo-production-codinglabs/web', ['yolo:app' => 'codinglabs', 'yolo:scope' => 'app', 'Name' => 'yolo-production-codinglabs-web']),
        // An unmanaged service with NO ownership marker is unexpected for the
        // no-owner reason — it never reaches the service check.
        auditResource('arn:aws:dynamodb:ap-southeast-2:111:table/hand-rolled', ['Name' => 'hand-rolled']),
    ], liveApps: ['codinglabs']);

    expect($report['okCount'])->toBe(1)
        ->and($report['unexpectedCount'])->toBe(2);

    $byArn = collect($report['resources'])->keyBy('arn');

    expect($byArn['arn:aws:dynamodb:ap-southeast-2:111:table/yolo-production-codinglabs-sessions']['status'])->toBe('unexpected')
        ->and($byArn['arn:aws:dynamodb:ap-southeast-2:111:table/yolo-production-codinglabs-sessions']['reason'])->toBe(Audit::REASON_UNMANAGED_SERVICE)
        ->and($byArn['arn:aws:ecs:ap-southeast-2:111:service/yolo-production-codinglabs/web']['status'])->toBe('ok')
        ->and($byArn['arn:aws:dynamodb:ap-southeast-2:111:table/hand-rolled']['reason'])->toBe(Audit::REASON_NO_OWNER);
});

it('reports the unmanaged-service reason ahead of dead-app when the owning app is gone', function (): void {
    $report = Audit::classify([
        auditResource('arn:aws:dynamodb:ap-southeast-2:111:table/yolo-production-ghost-sessions', ['yolo:app' => 'ghost', 'yolo:scope' => 'app']),
    ], liveApps: ['codinglabs']);

    expect($report['unexpectedCount'])->toBe(1)
        ->and($report['resources'][0]['status'])->toBe('unexpected')
        ->and($report['resources'][0]['reason'])->toBe(Audit::REASON_UNMANAGED_SERVICE);
});

it('flags a yolo:app pointing at a dead app as unexpected (app cluster gone)', function (): void {
    $report = Audit::classify([
        auditResource('arn:aws:ecr:ap-southeast-2:111:repository/yolo-production-ghost', ['yolo:app' => 'ghost', 'yolo:scope' => 'app']),
    ], liveApps: ['codinglabs']);

    expect($report['unexpectedCount'])->toBe(1)
        ->and($report['okCount'])->toBe(0)
        ->and($report['resources'][0]['reason'])->toBe(Audit::REASON_DEAD_APP);
});

it('assigns an ownership scope from the yolo:scope tag, defaulting unowned resources to env', function (): void {
    $report = Audit::classify([
        // explicit yolo:scope tags (stamped by sync on everything it creates) map
        // straight through — including the account-global OIDC provider
        auditResource('arn:aws:ecr:ap-southeast-2:111:repository/yolo-production-ghost', ['yolo:app' => 'ghost', 'yolo:scope' => 'app']),
        auditResource('arn:aws:s3:::some-bucket', ['yolo:scope' => 'env']),
        auditResource('arn:aws:iam::111:oidc-provider/token.actions.githubusercontent.com', ['yolo:scope' => 'account']),
        // no yolo:scope marker at all → an unowned resource, bucketed under env
        auditResource('arn:aws:elasticloadbalancing:ap-southeast-2:111:loadbalancer/app/yolo-production/abc'),
    ], liveApps: []);

    $byArn = collect($report['resources'])->keyBy('arn');

    expect($byArn['arn:aws:ecr:ap-southeast-2:111:repository/yolo-production-ghost']['scope'])->toBe('app')
        ->and($byArn['arn:aws:s3:::some-bucket']['scope'])->toBe('env')
        ->and($byArn['arn:aws:iam::111:oidc-provider/token.actions.githubusercontent.com']['scope'])->toBe('account')
        ->and($byArn['arn:aws:elasticloadbalancing:ap-southeast-2:111:loadbalancer/app/yolo-production/abc']['scope'])->toBe('env');
});

it('orders rows by scope (account → env → app), then unexpected-first within a scope', function (): void {
    $rows = [
        ['scope' => 'app', 'status' => 'ok', 'reason' => null, 'app' => 'codinglabs', 'name' => 'web'],
        ['scope' => 'env', 'status' => 'unexpected', 'reason' => Audit::REASON_NO_OWNER, 'app' => null, 'name' => 'vpc'],
        ['scope' => 'account', 'status' => 'unexpected', 'reason' => Audit::REASON_NO_OWNER, 'app' => null, 'name' => 'oidc'],
        ['scope' => 'app', 'status' => 'unexpected', 'reason' => Audit::REASON_DEAD_APP, 'app' => 'ghost', 'name' => 'repo'],
        ['scope' => 'env', 'status' => 'unexpected', 'reason' => Audit::REASON_NO_OWNER, 'app' => null, 'name' => 'alb'],
    ];

    $ordered = collect($rows)->sortBy(fn (array $resource): string => Audit::orderKey($resource))->values();

    expect($ordered->pluck('name')->all())->toBe(['oidc', 'alb', 'vpc', 'repo', 'web']);
    // account first, then env (alb before vpc by name), then app (the unexpected
    // repo before the ok web)
    expect($ordered->pluck('scope')->all())->toBe(['account', 'env', 'env', 'app', 'app']);
});

it('derives a readable type and name for each resource', function (): void {
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

it('returns zero counts and no resources for an empty inventory', function (): void {
    $report = Audit::classify([], liveApps: ['codinglabs']);

    expect($report['resources'])->toBe([])
        ->and($report['okCount'])->toBe(0)
        ->and($report['unexpectedCount'])->toBe(0)
        ->and($report['liveApps'])->toBe(['codinglabs']);
});
