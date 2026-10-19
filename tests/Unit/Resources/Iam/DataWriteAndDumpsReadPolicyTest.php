<?php

use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Iam\DataWritePolicy;
use Codinglabs\Yolo\Resources\Iam\DumpsReadPolicy;
use Codinglabs\Yolo\Resources\Iam\AppDumpsReadPolicy;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'bucket' => true,
    ]);
});

it('is an env-scoped policy named yolo-{env}-data-write', function (): void {
    expect((new DataWritePolicy())->scope())->toBe(Scope::Env);
    expect((new DataWritePolicy())->name())->toBe('yolo-testing-data-write');
});

it('grants object writes on every data bucket and nothing else — the dumps live in their own document', function (): void {
    $captured = [];
    bindServiceLifecycleWorld([
        'claims' => ['other' => []],
        'buckets' => ['other' => 'other-uploads'],
    ], $captured);

    $statements = (new DataWritePolicy())->document()['Statement'];

    expect($statements)->toHaveCount(1);
    expect($statements[0]['Resource'])->toBe([
        'arn:aws:s3:::yolo-111111111111-testing-*-data/*',
        'arn:aws:s3:::other-uploads/*',
    ]);
    expect($statements[0]['Action'])->toBe(['s3:PutObject', 's3:DeleteObject', 's3:AbortMultipartUpload']);
    expect(json_encode($statements))->not->toContain('backups');
});

it('never includes the read document\'s actions — composed on top of DataReadPolicy, not inclusive of it', function (): void {
    $captured = [];
    bindServiceLifecycleWorld(['bucket' => false], $captured);

    $actions = collect((new DataWritePolicy())->document()['Statement'])
        ->flatMap(fn (array $s): array => (array) $s['Action']);

    expect($actions)->not->toContain('s3:GetObject', 's3:ListBucket');
});

it('is an env-scoped policy named yolo-{env}-dumps-read that reads every dump — never deletes one', function (): void {
    $policy = new DumpsReadPolicy();
    $statements = $policy->document()['Statement'];

    expect($policy->scope())->toBe(Scope::Env);
    expect($policy->name())->toBe('yolo-testing-dumps-read');
    expect($statements)->toHaveCount(1);
    expect($statements[0]['Resource'])->toBe('arn:aws:s3:::yolo-111111111111-testing-backups/*');
    expect($statements[0]['Action'])->toBe(['s3:GetObject', 's3:GetObjectVersion']);
});

it('fences the per-app dumps-read variant to this app\'s own dump prefix, bucket or not', function (array $config): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2', ...$config]);

    $policy = new AppDumpsReadPolicy();
    $statements = $policy->document()['Statement'];

    expect($policy->scope())->toBe(Scope::App);
    expect($policy->name())->toBe('yolo-testing-my-app-dumps-read');
    expect($statements)->toHaveCount(1);
    expect($statements[0]['Resource'])->toBe('arn:aws:s3:::yolo-111111111111-testing-backups/my-app/*');
    expect($statements[0]['Action'])->toBe(['s3:GetObject', 's3:GetObjectVersion']);
})->with([
    'with a data bucket' => [['bucket' => true]],
    'without a data bucket' => [[]],
]);
