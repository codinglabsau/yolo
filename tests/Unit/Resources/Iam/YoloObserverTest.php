<?php

use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Iam\YoloObserver;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

/** Every action the policy grants, flattened across statements. */
function observerActions(): array
{
    return collect((new YoloObserver())->document()['Statement'])
        ->flatMap(fn (array $statement): array => (array) $statement['Action'])
        ->all();
}

it('is an env-scoped policy named yolo-{env}-observer (shared by every app in the environment)', function (): void {
    expect((new YoloObserver())->scope())->toBe(Scope::Env);
    expect((new YoloObserver())->name())->toBe('yolo-testing-observer');
});

it('grants read-only wildcards for the services YOLO provisions, on * (unscopeable describe/list ops)', function (): void {
    $statement = collect((new YoloObserver())->document()['Statement'])
        ->first(fn (array $s): bool => $s['Resource'] === '*' && in_array('ecs:Describe*', (array) $s['Action'], true));

    expect($statement)->not->toBeNull();
    expect($statement['Action'])->toContain(
        'ec2:Describe*',
        'ecs:Describe*',
        'elasticloadbalancing:Describe*',
        'application-autoscaling:Describe*',
        'elasticache:Describe*',
        'cloudfront:List*',
        'route53:List*',
        'cloudwatch:Describe*',
        'logs:Describe*',
        'wafv2:List*',
        'servicediscovery:List*',
        'sts:GetCallerIdentity',
    );
});

it('grants no write actions — read-only by construction', function (): void {
    $writeVerbs = ['Create', 'Update', 'Delete', 'Put', 'Modify', 'Attach', 'Detach', 'Register', 'Deregister', 'Set', 'Tag', 'Untag', 'Run', 'Stop', 'Start'];

    foreach (observerActions() as $action) {
        [, $verb] = explode(':', (string) $action, 2);

        foreach ($writeVerbs as $write) {
            expect(str_starts_with($verb, $write))->toBeFalse("observer grants a write action: {$action}");
        }
    }
});

it('scopes IAM document reads to YOLO-managed identities, never the whole account', function (): void {
    $statement = collect((new YoloObserver())->document()['Statement'])
        ->first(fn (array $s): bool => in_array('iam:GetPolicyVersion', (array) $s['Action'], true));

    expect($statement['Resource'])->toBe([
        'arn:aws:iam::111111111111:role/yolo-*',
        'arn:aws:iam::111111111111:policy/yolo-*',
        'arn:aws:iam::111111111111:oidc-provider/*',
    ]);

    // The unscopeable IAM collection ops (list the account) are the only IAM on *.
    $onStar = collect((new YoloObserver())->document()['Statement'])
        ->first(fn (array $s): bool => $s['Resource'] === '*');

    expect($onStar['Action'])->not->toContain('iam:GetPolicyVersion', 'iam:GetRole');
});

it('scopes s3 object reads to the env-shared config only — never secrets', function (): void {
    $objectStatement = collect((new YoloObserver())->document()['Statement'])
        ->first(fn (array $s): bool => in_array('s3:GetObject', (array) $s['Action'], true));

    // GetObject is granted on exactly the env manifest + app claim files — config,
    // not secrets. The env-shared `.env` (bucket root) is never in scope.
    expect($objectStatement['Resource'])->toBe([
        'arn:aws:s3:::yolo-111111111111-testing-config/yolo-environment-testing.yml',
        'arn:aws:s3:::yolo-111111111111-testing-config/apps/*',
    ]);

    expect($objectStatement['Resource'])->not->toContain('arn:aws:s3:::yolo-111111111111-testing-config/.env');
    expect($objectStatement['Resource'])->not->toContain('arn:aws:s3:::yolo-111111111111-testing-config/*');

    // s3:GetObject is never granted on * — the boundary that keeps the observer out
    // of every other bucket's objects.
    $onStar = collect((new YoloObserver())->document()['Statement'])
        ->first(fn (array $s): bool => $s['Resource'] === '*');
    expect($onStar['Action'])->not->toContain('s3:GetObject');
});

it('scopes s3 bucket-config reads to YOLO-named buckets and excludes object contents', function (): void {
    $bucketStatement = collect((new YoloObserver())->document()['Statement'])
        ->first(fn (array $s): bool => $s['Resource'] === 'arn:aws:s3:::yolo-*');

    expect($bucketStatement['Action'])->toContain('s3:GetBucket*', 's3:ListBucket');
    // Bucket ARN (no /*) can't authorise object reads.
    expect($bucketStatement['Action'])->not->toContain('s3:GetObject');
});
