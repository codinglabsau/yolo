<?php

use Aws\Result;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Iam\UsersGroup;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

it('is a literal, never env-keyed name — one group shared by every environment', function (): void {
    expect((new UsersGroup())->name())->toBe('yolo-users');
    expect((new UsersGroup())->scope())->toBe(Scope::Account);
});

it('grants the self-service credential slice and nothing else — no sts:AssumeRole anywhere', function (): void {
    $document = (new UsersGroup())->document();
    $self = [
        'arn:aws:iam::111111111111:user/${aws:username}',
        'arn:aws:iam::111111111111:mfa/*',
    ];

    expect($document['Version'])->toBe('2012-10-17');
    expect($document['Statement'])->toHaveCount(3);
    expect(collect($document['Statement'])->pluck('Action')->flatten()->all())
        ->not->toContain('sts:AssumeRole');

    // The MFA bootstrap path — ungated so a new user can enrol their first
    // device, scoped to the member's own user/mfa ARNs so nothing broader leaks.
    $bootstrap = $document['Statement'][0];
    expect($bootstrap['Effect'])->toBe('Allow');
    expect($bootstrap['Action'])->toBe([
        'iam:GetUser',
        'iam:GetMFADevice',
        'iam:ListMFADevices',
        'iam:CreateVirtualMFADevice',
        'iam:EnableMFADevice',
        'iam:ResyncMFADevice',
    ]);
    expect($bootstrap['Resource'])->toBe($self);
    expect($bootstrap)->not->toHaveKey('Condition');

    // Account-level console reads — ungated, needed to render the MFA and
    // password screens; neither action supports resource-level scoping.
    $consoleReads = $document['Statement'][1];
    expect($consoleReads['Effect'])->toBe('Allow');
    expect($consoleReads['Action'])->toBe([
        'iam:ListVirtualMFADevices',
        'iam:GetAccountPasswordPolicy',
    ]);
    expect($consoleReads['Resource'])->toBe('*');
    expect($consoleReads)->not->toHaveKey('Condition');

    // Credential self-management — MFA-gated, so a leaked bare key or pre-MFA
    // console session can't cut a fresh key, change the password, or strip the
    // device.
    $credentials = $document['Statement'][2];
    expect($credentials['Effect'])->toBe('Allow');
    expect($credentials['Action'])->toBe([
        'iam:CreateAccessKey',
        'iam:ListAccessKeys',
        'iam:UpdateAccessKey',
        'iam:DeleteAccessKey',
        'iam:ChangePassword',
        'iam:DeactivateMFADevice',
        'iam:DeleteVirtualMFADevice',
    ]);
    expect($credentials['Resource'])->toBe($self);
    expect($credentials['Condition'])->toBe(['Bool' => ['aws:MultiFactorAuthPresent' => 'true']]);
});

it('creates the group and its inline self-service policy', function (): void {
    $captured = [];
    bindRoutedIamClient([], $captured);

    (new UsersGroup())->create();

    expect(collect($captured)->pluck('name')->all())->toBe(['CreateGroup', 'PutGroupPolicy']);

    $createGroup = collect($captured)->firstWhere('name', 'CreateGroup');
    expect($createGroup['args']['GroupName'])->toBe('yolo-users');

    $putPolicy = collect($captured)->firstWhere('name', 'PutGroupPolicy');
    expect($putPolicy['args']['GroupName'])->toBe('yolo-users');
    expect($putPolicy['args']['PolicyName'])->toBe('yolo-users-self-service');

    $document = json_decode((string) $putPolicy['args']['PolicyDocument'], true);
    expect($document['Statement'])->toHaveCount(3);
});

it('is untaggable — synchroniseTags is a no-op (ownership lives in the name)', function (): void {
    expect((new UsersGroup())->synchroniseTags(apply: false))->toBe([]);
    expect((new UsersGroup())->synchroniseTags(apply: true))->toBe([]);
});

it('reports existing/absent from GetGroup, mirroring the tier groups', function (): void {
    $captured = [];
    bindRoutedIamClient([
        'GetGroup' => new Result(['Group' => ['GroupName' => 'yolo-users', 'Arn' => 'arn:aws:iam::111111111111:group/yolo-users']]),
    ], $captured);

    expect((new UsersGroup())->exists())->toBeTrue();
    expect((new UsersGroup())->arn())->toBe('arn:aws:iam::111111111111:group/yolo-users');
});
