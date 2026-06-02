<?php

use Aws\Result;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\DynamoDb\SessionsTable;

beforeEach(function () {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'session' => ['driver' => 'dynamodb'],
    ]);
});

it('is app-scoped and named per app', function () {
    $resource = new SessionsTable();

    expect($resource->scope())->toBe(Scope::App);
    expect($resource->name())->toBe('yolo-testing-my-app-sessions');
    expect($resource->tags())->toMatchArray([
        'Name' => 'yolo-testing-my-app-sessions',
        'yolo:scope' => 'app',
        'yolo:app' => 'my-app',
    ]);
});

it('creates an on-demand table with the Laravel cache schema and TTL on expires_at', function () {
    $captured = [];

    bindMockDynamoDbClient([
        'CreateTable' => new Result(),
        'DescribeTable' => new Result(['Table' => [
            'TableName' => 'yolo-testing-my-app-sessions',
            'TableStatus' => 'ACTIVE',
            'TableArn' => 'arn:aws:dynamodb:ap-southeast-2:111111111111:table/yolo-testing-my-app-sessions',
        ]]),
        'UpdateTimeToLive' => new Result(),
    ], $captured);

    (new SessionsTable())->create();

    $create = collect($captured)->firstWhere('name', 'CreateTable');
    expect($create['args']['TableName'])->toBe('yolo-testing-my-app-sessions');
    expect($create['args']['BillingMode'])->toBe('PAY_PER_REQUEST');
    expect($create['args']['AttributeDefinitions'][0])->toMatchArray(['AttributeName' => 'key', 'AttributeType' => 'S']);
    expect($create['args']['KeySchema'][0])->toMatchArray(['AttributeName' => 'key', 'KeyType' => 'HASH']);

    $ttl = collect($captured)->firstWhere('name', 'UpdateTimeToLive');
    expect($ttl['args']['TimeToLiveSpecification'])->toMatchArray([
        'AttributeName' => 'expires_at',
        'Enabled' => true,
    ]);
});
