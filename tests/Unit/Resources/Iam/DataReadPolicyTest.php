<?php

use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Iam\DataReadPolicy;
use Codinglabs\Yolo\Resources\Iam\AppDataReadPolicy;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'bucket' => true,
    ]);
});

it('is an env-scoped policy named yolo-{env}-data-read', function (): void {
    expect((new DataReadPolicy())->scope())->toBe(Scope::Env);
    expect((new DataReadPolicy())->name())->toBe('yolo-testing-data-read');
});

it('grants listing + object reads on every YOLO-named data bucket in the environment', function (): void {
    $captured = [];
    bindServiceLifecycleWorld(['bucket' => false], $captured);

    [$bucket, $objects] = (new DataReadPolicy())->document()['Statement'];

    // The keyed `-data` wildcard covers every app YOLO named the bucket for, and a
    // greenfield plan pass (no config bucket yet) reads as nothing more.
    expect($bucket['Resource'])->toBe(['arn:aws:s3:::yolo-111111111111-testing-*-data']);
    expect($bucket['Action'])->toBe(['s3:ListBucket', 's3:GetBucketLocation']);

    expect($objects['Resource'])->toBe(['arn:aws:s3:::yolo-111111111111-testing-*-data/*']);
    expect($objects['Action'])->toBe(['s3:GetObject', 's3:GetObjectVersion']);
});

it('adds every bring-your-own bucket the admin-published claims name', function (): void {
    $captured = [];
    bindServiceLifecycleWorld([
        'claims' => ['zeta' => [], 'alpha' => [], 'managed' => []],
        // Only sync:app writes a claim, so the name is an admin's word; `true` is
        // YOLO-named and already inside the wildcard.
        'buckets' => ['zeta' => 'zeta-uploads', 'alpha' => 'alpha-media', 'managed' => true],
    ], $captured);

    [$bucket, $objects] = (new DataReadPolicy())->document()['Statement'];

    expect($bucket['Resource'])->toBe([
        'arn:aws:s3:::yolo-111111111111-testing-*-data',
        'arn:aws:s3:::alpha-media',
        'arn:aws:s3:::zeta-uploads',
    ]);
    expect($objects['Resource'])->toBe([
        'arn:aws:s3:::yolo-111111111111-testing-*-data/*',
        'arn:aws:s3:::alpha-media/*',
        'arn:aws:s3:::zeta-uploads/*',
    ]);
});

it('grants no write actions — read-only by construction', function (): void {
    $captured = [];
    bindServiceLifecycleWorld(['bucket' => false], $captured);

    $actions = collect((new DataReadPolicy())->document()['Statement'])
        ->flatMap(fn (array $s): array => (array) $s['Action']);

    foreach ($actions as $action) {
        expect(str_starts_with($action, 's3:Get') || str_starts_with($action, 's3:List'))
            ->toBeTrue("data read grants a write action: {$action}");
    }
});

it('fences the per-app variant to this app\'s own bucket', function (): void {
    $policy = new AppDataReadPolicy();
    [$bucket, $objects] = $policy->document()['Statement'];

    expect($policy->scope())->toBe(Scope::App);
    expect($policy->name())->toBe('yolo-testing-my-app-data-read');
    expect($bucket['Resource'])->toBe(['arn:aws:s3:::yolo-111111111111-testing-my-app-data']);
    expect($objects['Resource'])->toBe(['arn:aws:s3:::yolo-111111111111-testing-my-app-data/*']);
});

it('takes a bring-your-own bucket name verbatim on the per-app variant — the only tier that reaches one', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'bucket' => 'my-app-uploads',
    ]);

    [$bucket] = (new AppDataReadPolicy())->document()['Statement'];

    expect($bucket['Resource'])->toBe(['arn:aws:s3:::my-app-uploads']);
});
