<?php

use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Iam\AppObserverPolicy;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

/** Every statement in the per-app observer document. */
function appObserverStatements(): array
{
    return (new AppObserverPolicy())->document()['Statement'];
}

it('is an app-scoped policy named yolo-{env}-{app}-observer', function (): void {
    expect((new AppObserverPolicy())->scope())->toBe(Scope::App);
    expect((new AppObserverPolicy())->name())->toBe('yolo-testing-my-app-observer');
});

it('builds a pure document from the manifest (no live AWS calls)', function (): void {
    expect((new AppObserverPolicy())->document()['Version'])->toBe('2012-10-17');
});

it('inherits the full env read surface — only the logs reads differ', function (): void {
    // The unscopeable reads (ecs/ec2/cloudwatch/cost) are identical to the env
    // observer: AWS cannot scope them per-app, so they stay on "*".
    $star = collect(appObserverStatements())
        ->first(fn (array $s): bool => $s['Resource'] === '*' && in_array('ecs:Describe*', (array) $s['Action'], true));

    expect($star['Action'])->toContain('ec2:Describe*', 'cloudwatch:Get*', 'ce:Get*');
});

it('fences log CONTENT to this app\'s log group, never account-wide', function (): void {
    $logContentArn = 'arn:aws:logs:ap-southeast-2:111111111111:log-group:/yolo/yolo-testing-my-app:*';

    $contentStatement = collect(appObserverStatements())
        ->first(fn (array $s): bool => in_array('logs:FilterLogEvents', (array) $s['Action'], true));

    // FilterLogEvents/GetLogEvents are scoped to exactly this app's log group —
    // an operator granted this role cannot tail another app's logs.
    expect($contentStatement['Resource'])->toBe($logContentArn);
    expect($contentStatement['Action'])->toContain('logs:FilterLogEvents', 'logs:GetLogEvents');

    // No log content read is ever granted on "*".
    $contentOnStar = collect(appObserverStatements())
        ->first(fn (array $s): bool => $s['Resource'] === '*' && in_array('logs:FilterLogEvents', (array) $s['Action'], true));
    expect($contentOnStar)->toBeNull();
});

it('scopes log-group TAG reads to the bare ARN, not the :* stream form (the deploy gate reads tags)', function (): void {
    // ListTagsForResource is a group-level op — CloudWatch Logs addresses the
    // group by its bare ARN. The ':*' (stream-content) form does not match a
    // bare-ARN request, so lumping the tag read in with the content reads denied
    // the pre-deploy `sync --check` gate (which runs under the deployer's copy of
    // this policy). They must be separate statements on separate ARNs.
    $bareArn = 'arn:aws:logs:ap-southeast-2:111111111111:log-group:/yolo/yolo-testing-my-app';

    $tagStatement = collect(appObserverStatements())
        ->first(fn (array $s): bool => in_array('logs:ListTagsForResource', (array) $s['Action'], true));

    expect($tagStatement['Resource'])->toBe($bareArn);

    // The content statement must NOT also carry the tag read on the ':*' form —
    // that's the exact regression that shipped.
    $contentStatement = collect(appObserverStatements())
        ->first(fn (array $s): bool => in_array('logs:FilterLogEvents', (array) $s['Action'], true));
    expect($contentStatement['Action'])->not->toContain('logs:ListTagsForResource');
});

it('keeps DescribeLogGroups on * — listing group names leaks nothing, reading content is fenced', function (): void {
    $describeStatement = collect(appObserverStatements())
        ->first(fn (array $s): bool => $s['Resource'] === '*' && in_array('logs:Describe*', (array) $s['Action'], true));

    expect($describeStatement)->not->toBeNull();
});

it('grants no write actions — read-only by construction', function (): void {
    $writeVerbs = ['Create', 'Update', 'Delete', 'Put', 'Modify', 'Attach', 'Detach', 'Register', 'Deregister', 'Set', 'Tag', 'Untag', 'Run', 'Stop', 'Start'];

    $actions = collect(appObserverStatements())->flatMap(fn (array $s): array => (array) $s['Action']);

    foreach ($actions as $action) {
        [, $verb] = explode(':', (string) $action, 2);

        foreach ($writeVerbs as $write) {
            expect(str_starts_with($verb, $write))->toBeFalse("app observer grants a write action: {$action}");
        }
    }
});
