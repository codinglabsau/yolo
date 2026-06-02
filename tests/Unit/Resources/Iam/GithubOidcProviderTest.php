<?php

use Aws\Result;
use Codinglabs\Yolo\Resources\Iam\GithubOidcProvider;

beforeEach(function () {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);
});

it('derives the provider ARN from the account id and the GitHub URL', function () {
    expect((new GithubOidcProvider())->arn())
        ->toBe('arn:aws:iam::111111111111:oidc-provider/token.actions.githubusercontent.com');
});

it('creates the provider with the sts audience and pinned thumbprints', function () {
    $captured = [];

    bindRoutedIamClient([], $captured);

    (new GithubOidcProvider())->create();

    $create = collect($captured)->firstWhere('name', 'CreateOpenIDConnectProvider');

    expect($create)->not->toBeNull();
    expect($create['args']['Url'])->toBe('https://token.actions.githubusercontent.com');
    expect($create['args']['ClientIDList'])->toBe(['sts.amazonaws.com']);
    expect($create['args']['ThumbprintList'])->toBe(GithubOidcProvider::THUMBPRINTS);

    // Shared account singleton — Name + yolo:scope=account, never a yolo:environment
    // tag (env-baseline is suppressed for Scope::Account by Aws::expectedTags).
    expect($create['args']['Tags'])->toBe([
        ['Key' => 'Name', 'Value' => 'token.actions.githubusercontent.com'],
        ['Key' => 'yolo:scope', 'Value' => 'account'],
    ]);
});

it('reports the provider as existing when the ARN is in the account list', function () {
    $captured = [];

    bindRoutedIamClient([
        'ListOpenIDConnectProviders' => new Result([
            'OpenIDConnectProviderList' => [
                ['Arn' => 'arn:aws:iam::111111111111:oidc-provider/token.actions.githubusercontent.com'],
            ],
        ]),
    ], $captured);

    expect((new GithubOidcProvider())->exists())->toBeTrue();
});

it('reports the provider as absent when the list is empty', function () {
    $captured = [];

    bindRoutedIamClient([
        'ListOpenIDConnectProviders' => new Result(['OpenIDConnectProviderList' => []]),
    ], $captured);

    expect((new GithubOidcProvider())->exists())->toBeFalse();
});
