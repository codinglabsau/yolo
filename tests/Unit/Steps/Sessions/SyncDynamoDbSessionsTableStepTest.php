<?php

use Aws\Result;
use Aws\Command;
use Codinglabs\Yolo\Enums\StepResult;
use Aws\DynamoDb\Exception\DynamoDbException;
use Codinglabs\Yolo\Steps\Sync\App\SyncDynamoDbSessionsTableStep;

it('skips when the session driver is not dynamodb', function () {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'session' => ['driver' => 'database'],
    ]);

    expect((new SyncDynamoDbSessionsTableStep())([]))->toBe(StepResult::SKIPPED);
});

it('skips when no session driver is set', function () {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);

    expect((new SyncDynamoDbSessionsTableStep())([]))->toBe(StepResult::SKIPPED);
});

it('creates the sessions table when the session driver is dynamodb', function () {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'session' => ['driver' => 'dynamodb'],
    ]);

    $captured = [];

    $notFound = new DynamoDbException(
        'Requested resource not found',
        new Command('DescribeTable'),
        ['code' => 'ResourceNotFoundException'],
    );

    bindMockDynamoDbClient([
        'DescribeTable' => [
            $notFound,  // exists() → not found → create
            new Result(['Table' => [  // TableExists waiter → active
                'TableName' => 'yolo-testing-my-app-sessions',
                'TableStatus' => 'ACTIVE',
                'TableArn' => 'arn:aws:dynamodb:ap-southeast-2:111111111111:table/yolo-testing-my-app-sessions',
            ]]),
        ],
        'CreateTable' => new Result(),
        'UpdateTimeToLive' => new Result(),
    ], $captured);

    expect((new SyncDynamoDbSessionsTableStep())([]))->toBe(StepResult::CREATED);

    $names = array_column($captured, 'name');
    expect($names)->toContain('CreateTable')->toContain('UpdateTimeToLive');
});
