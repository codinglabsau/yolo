<?php

use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Iam\DataWritePolicy;
use Codinglabs\Yolo\Resources\Iam\AppDataWritePolicy;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'bucket' => true,
    ]);
});

it('is an env-scoped policy named yolo-{env}-data-write', function (): void {
    expect((new DataWritePolicy())->scope())->toBe(Scope::Env);
    expect((new DataWritePolicy())->name())->toBe('yolo-testing-data-write');
});

it('grants object writes on every data bucket and reads on every database dump — never a dump delete', function (): void {
    $captured = [];
    bindServiceLifecycleWorld([
        'claims' => ['other' => []],
        'buckets' => ['other' => 'other-uploads'],
    ], $captured);

    [$writes, $dumps] = (new DataWritePolicy())->document()['Statement'];

    expect($writes['Resource'])->toBe([
        'arn:aws:s3:::yolo-111111111111-testing-*-data/*',
        'arn:aws:s3:::other-uploads/*',
    ]);
    expect($writes['Action'])->toBe(['s3:PutObject', 's3:DeleteObject', 's3:AbortMultipartUpload']);

    // A restore is a write-side act, so the dump read lives here, env-wide.
    expect($dumps['Resource'])->toBe('arn:aws:s3:::yolo-111111111111-testing-backups/*');
    expect($dumps['Action'])->toBe(['s3:GetObject', 's3:GetObjectVersion']);

    // Versioning is the tamper armour — no tier deletes a dump.
    expect($dumps['Action'])->not->toContain('s3:DeleteObject', 's3:DeleteObjectVersion');
});

it('never includes the read document\'s actions — composed on top of DataReadPolicy, not inclusive of it', function (): void {
    $captured = [];
    bindServiceLifecycleWorld(['bucket' => false], $captured);

    $onData = collect((new DataWritePolicy())->document()['Statement'])
        ->filter(fn (array $s): bool => str_contains(json_encode($s['Resource']), '-data'))
        ->flatMap(fn (array $s): array => (array) $s['Action']);

    expect($onData)->not->toContain('s3:GetObject', 's3:ListBucket');
});

it('fences the per-app variant to this app\'s bucket and its own dump prefix', function (): void {
    $policy = new AppDataWritePolicy();
    [$writes, $dumps] = $policy->document()['Statement'];

    expect($policy->scope())->toBe(Scope::App);
    expect($policy->name())->toBe('yolo-testing-my-app-data-write');
    expect($writes['Resource'])->toBe(['arn:aws:s3:::yolo-111111111111-testing-my-app-data/*']);
    expect($dumps['Resource'])->toBe('arn:aws:s3:::yolo-111111111111-testing-backups/my-app/*');
});

it('stays a valid document for an app with no data bucket — the dump read alone', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);

    $statements = (new AppDataWritePolicy())->document()['Statement'];

    // An IAM statement needs a resource, so the bucket-write statement is dropped
    // rather than emitted empty; the per-app dump read keeps the document valid.
    expect($statements)->toHaveCount(1);
    expect($statements[0]['Resource'])->toBe('arn:aws:s3:::yolo-111111111111-testing-backups/my-app/*');
});
