<?php

declare(strict_types=1);

use Aws\Result;
use Aws\MockHandler;
use Aws\Ecr\EcrClient;
use Aws\CommandInterface;
use Codinglabs\Yolo\Helpers;
use GuzzleHttp\Promise\Create;
use Codinglabs\Yolo\Resources\Ecr\EcrRepository;

/**
 * Bind a mock ECR client with command-routed responses, capturing every call.
 *
 * @param  array<string, Result>  $byCommand
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
 */
function bindRoutedEcrClient(array $byCommand, array &$captured): void
{
    $mock = new class($byCommand, $captured) extends MockHandler
    {
        /**
         * @param  array<string, Result>  $byCommand
         * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
         */
        public function __construct(protected array $byCommand, protected array &$captured) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            $this->captured[] = ['name' => $cmd->getName(), 'args' => $cmd->toArray()];

            return Create::promiseFor($this->byCommand[$cmd->getName()] ?? new Result());
        }
    };

    Helpers::app()->instance('ecr', new EcrClient([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

it('derives the ECR repository name from the manifest app name', function (): void {
    expect((new EcrRepository())->name())->toBe('my-app');
});

it('builds the ECR repository URI from account and region', function (): void {
    expect((new EcrRepository())->uri())
        ->toBe('111111111111.dkr.ecr.ap-southeast-2.amazonaws.com/my-app');
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
            'repositoryName' => 'my-app',
            'repositoryArn' => 'arn:aws:ecr:ap-southeast-2:111111111111:repository/my-app',
        ]]]),
    ], $captured);

    $repo = new EcrRepository();

    expect($repo->exists())->toBeTrue()
        ->and($repo->arn())->toBe('arn:aws:ecr:ap-southeast-2:111111111111:repository/my-app');
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
    expect($create['args']['repositoryName'])->toBe('my-app')
        ->and($create['args']['imageScanningConfiguration'])->toBe(['scanOnPush' => true])
        ->and($create['args']['imageTagMutability'])->toBe('MUTABLE')
        ->and($create['args'])->toHaveKey('tags');

    $lifecycle = collect($captured)->firstWhere('name', 'PutLifecyclePolicy');
    expect($lifecycle)->not->toBeNull()
        ->and($lifecycle['args']['repositoryName'])->toBe('my-app');
});
