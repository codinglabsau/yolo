<?php

declare(strict_types=1);

use Aws\Result;
use Codinglabs\Yolo\Resources\Iam\MediaConvertRole;

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);
});

it('scopes the role to this app and carries the yolo:app owner tag', function (): void {
    expect((new MediaConvertRole())->name())->toBe('yolo-testing-my-app-mediaconvert-role')
        ->and((new MediaConvertRole())->tags())->toMatchArray([
            'yolo:scope' => 'app',
            'yolo:app' => 'my-app',
        ]);
});

it('trusts only the MediaConvert service in its assume-role policy', function (): void {
    expect((new MediaConvertRole())->assumeRolePolicyDocument())->toBe([
        'Version' => '2012-10-17',
        'Statement' => [
            [
                'Effect' => 'Allow',
                'Principal' => ['Service' => 'mediaconvert.amazonaws.com'],
                'Action' => 'sts:AssumeRole',
            ],
        ],
    ]);
});

it('reports existence and ARN from the live IAM role list', function (): void {
    bindMockIamClient([
        'yolo-testing-my-app-mediaconvert-role' => 'arn:aws:iam::111111111111:role/yolo-testing-my-app-mediaconvert-role',
    ]);

    $role = new MediaConvertRole();

    expect($role->exists())->toBeTrue()
        ->and($role->arn())->toBe('arn:aws:iam::111111111111:role/yolo-testing-my-app-mediaconvert-role');
});

it('reports absence when the role is not in the account', function (): void {
    bindMockIamClient([]);

    expect((new MediaConvertRole())->exists())->toBeFalse();
});

it('creates the role with its trust policy, description and ownership tags', function (): void {
    $captured = [];
    bindRoutedIamClient(['CreateRole' => new Result([])], $captured);

    (new MediaConvertRole())->create();

    $create = collect($captured)->firstWhere('name', 'CreateRole');
    $trust = json_decode((string) $create['args']['AssumeRolePolicyDocument'], true);

    expect($create['args']['RoleName'])->toBe('yolo-testing-my-app-mediaconvert-role')
        ->and($create['args']['Description'])->toBe('YOLO managed MediaConvert role')
        ->and($trust['Statement'][0]['Principal']['Service'])->toBe('mediaconvert.amazonaws.com')
        ->and($create['args'])->toHaveKey('Tags');
});
