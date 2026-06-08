<?php

declare(strict_types=1);

use Aws\Result;
use Codinglabs\Yolo\Resources\Iam\EcsExecutionRole;

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);
});

it('scopes the role to the environment (shared, no per-app owner tag)', function (): void {
    $role = new EcsExecutionRole();

    expect($role->name())->toBe('yolo-testing-ecs-execution-role')
        ->and($role->tags())->toMatchArray(['yolo:scope' => 'env'])
        ->and($role->tags())->not->toHaveKey('yolo:app');
});

it('trusts the ecs-tasks service in its assume-role policy', function (): void {
    expect((new EcsExecutionRole())->assumeRolePolicyDocument())->toBe([
        'Version' => '2012-10-17',
        'Statement' => [
            [
                'Effect' => 'Allow',
                'Principal' => ['Service' => 'ecs-tasks.amazonaws.com'],
                'Action' => 'sts:AssumeRole',
            ],
        ],
    ]);
});

it('reports existence and ARN from the live IAM role list', function (): void {
    bindMockIamClient([
        'yolo-testing-ecs-execution-role' => 'arn:aws:iam::111111111111:role/yolo-testing-ecs-execution-role',
    ]);

    $role = new EcsExecutionRole();

    expect($role->exists())->toBeTrue()
        ->and($role->arn())->toBe('arn:aws:iam::111111111111:role/yolo-testing-ecs-execution-role');
});

it('reports absence when the role is not in the account', function (): void {
    bindMockIamClient([]);

    expect((new EcsExecutionRole())->exists())->toBeFalse();
});

it('creates the role with an ASCII-safe description and its trust policy', function (): void {
    $captured = [];
    bindRoutedIamClient(['CreateRole' => new Result([])], $captured);

    (new EcsExecutionRole())->create();

    $create = collect($captured)->firstWhere('name', 'CreateRole');
    $trust = json_decode((string) $create['args']['AssumeRolePolicyDocument'], true);

    expect($create['args']['RoleName'])->toBe('yolo-testing-ecs-execution-role')
        ->and($create['args']['Description'])->toBe('YOLO managed ECS execution role - pulls images and writes logs for all apps in this environment')
        ->and($trust['Statement'][0]['Principal']['Service'])->toBe('ecs-tasks.amazonaws.com')
        ->and($create['args'])->toHaveKey('Tags');
});
