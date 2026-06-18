<?php

declare(strict_types=1);

use Aws\Result;
use Codinglabs\Yolo\Resources\Ecr\EcrRepository;

// bindRoutedEcrClient lives in tests/Pest.php — shared with the Typesense
// image/repository tests, which need it under --parallel.

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

it('derives an env-scoped ECR repository name so two envs of one app never share a repo', function (): void {
    expect((new EcrRepository())->name())->toBe('yolo-testing-my-app');
});

it('builds the ECR repository URI from account and region', function (): void {
    expect((new EcrRepository())->uri())
        ->toBe('111111111111.dkr.ecr.ap-southeast-2.amazonaws.com/yolo-testing-my-app');
});

it('expires untagged images after 7 days and caps tagged images at 30', function (): void {
    $rules = json_decode((new EcrRepository())->lifecyclePolicy(), true)['rules'];

    expect($rules)->toHaveCount(2)
        ->and($rules[0]['selection']['tagStatus'])->toBe('untagged')
        ->and($rules[0]['selection']['countNumber'])->toBe(7)
        ->and($rules[0]['action']['type'])->toBe('expire')
        ->and($rules[1]['selection']['tagStatus'])->toBe('any')
        ->and($rules[1]['selection']['countType'])->toBe('imageCountMoreThan')
        ->and($rules[1]['selection']['countNumber'])->toBe(30);
});

it('reports existence and ARN from describeRepositories', function (): void {
    $captured = [];
    bindRoutedEcrClient([
        'DescribeRepositories' => new Result(['repositories' => [[
            'repositoryName' => 'yolo-testing-my-app',
            'repositoryArn' => 'arn:aws:ecr:ap-southeast-2:111111111111:repository/yolo-testing-my-app',
        ]]]),
    ], $captured);

    $repo = new EcrRepository();

    expect($repo->exists())->toBeTrue()
        ->and($repo->arn())->toBe('arn:aws:ecr:ap-southeast-2:111111111111:repository/yolo-testing-my-app');
});

it('reports absence when describeRepositories returns no repository', function (): void {
    $captured = [];
    bindRoutedEcrClient(['DescribeRepositories' => new Result(['repositories' => []])], $captured);

    expect((new EcrRepository())->exists())->toBeFalse();
});

it('creates the repository with scan-on-push, mutable tags and a lifecycle policy', function (): void {
    $captured = [];
    bindRoutedEcrClient([], $captured);

    (new EcrRepository())->create();

    $create = collect($captured)->firstWhere('name', 'CreateRepository');
    expect($create['args']['repositoryName'])->toBe('yolo-testing-my-app')
        ->and($create['args']['imageScanningConfiguration'])->toBe(['scanOnPush' => true])
        ->and($create['args']['imageTagMutability'])->toBe('MUTABLE')
        ->and($create['args'])->toHaveKey('tags');

    $lifecycle = collect($captured)->firstWhere('name', 'PutLifecyclePolicy');
    expect($lifecycle)->not->toBeNull()
        ->and($lifecycle['args']['repositoryName'])->toBe('yolo-testing-my-app');
});
