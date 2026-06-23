<?php

declare(strict_types=1);

use Aws\Result;
use Codinglabs\Yolo\Helpers;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Enums\StepResult;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Create as PromiseCreate;
use Codinglabs\Yolo\Resources\Iam\GithubOidcProvider;
use Aws\ResourceGroupsTaggingAPI\ResourceGroupsTaggingAPIClient;
use Codinglabs\Yolo\Steps\Destroy\Account\TeardownGithubOidcProviderStep;

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);
});

it('keeps the OIDC provider while another environment still has resources', function (): void {
    $iam = [];
    bindRoutedIamClient([
        'ListOpenIDConnectProviders' => new Result(['OpenIDConnectProviderList' => [['Arn' => (new GithubOidcProvider())->arn()]]]),
    ], $iam);
    bindMockResourceGroupsTaggingApiClient('resourceGroupsTaggingApi', [
        new Result(['ResourceTagMappingList' => [
            ['ResourceARN' => 'arn:aws:ecs:ap-southeast-2:111111111111:cluster/x', 'Tags' => [['Key' => 'yolo:environment', 'Value' => 'production']]],
        ]]),
    ]);
    bindMockResourceGroupsTaggingApiClient('resourceGroupsTaggingApiGlobal', [new Result(['ResourceTagMappingList' => []])]);

    $step = new TeardownGithubOidcProviderStep();

    expect($step(['dry-run' => false]))->toBe(StepResult::SKIPPED)
        ->and($step->recordedWarnings())->not->toBeEmpty()
        ->and(array_column($iam, 'name'))->not->toContain('DeleteOpenIDConnectProvider');
});

it('reclaims the OIDC provider when no other environment remains', function (): void {
    $iam = [];
    bindRoutedIamClient([
        'ListOpenIDConnectProviders' => new Result(['OpenIDConnectProviderList' => [['Arn' => (new GithubOidcProvider())->arn()]]]),
        'DeleteOpenIDConnectProvider' => new Result([]),
    ], $iam);
    bindMockResourceGroupsTaggingApiClient('resourceGroupsTaggingApi', [new Result(['ResourceTagMappingList' => []])]);
    bindMockResourceGroupsTaggingApiClient('resourceGroupsTaggingApiGlobal', [new Result(['ResourceTagMappingList' => []])]);

    $step = new TeardownGithubOidcProviderStep();

    expect($step(['dry-run' => false]))->toBe(StepResult::DELETED)
        ->and(array_column($iam, 'name'))->toContain('DeleteOpenIDConnectProvider');
});

it('keeps the OIDC provider when other environments cannot be verified', function (): void {
    $iam = [];
    bindRoutedIamClient([
        'ListOpenIDConnectProviders' => new Result(['OpenIDConnectProviderList' => [['Arn' => (new GithubOidcProvider())->arn()]]]),
        'DeleteOpenIDConnectProvider' => new Result([]),
    ], $iam);

    // The "are there other environments?" tag scan fails — fail safe: keep, never
    // delete an account-shared resource on a guess.
    Helpers::app()->instance('resourceGroupsTaggingApi', new ResourceGroupsTaggingAPIClient([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => fn ($command, $request): PromiseInterface => PromiseCreate::rejectionFor(
            new AwsException('tagging API unavailable', $command),
        ),
    ]));

    $step = new TeardownGithubOidcProviderStep();

    expect($step(['dry-run' => false]))->toBe(StepResult::SKIPPED)
        ->and($step->recordedWarnings())->not->toBeEmpty()
        ->and(array_column($iam, 'name'))->not->toContain('DeleteOpenIDConnectProvider');
});
