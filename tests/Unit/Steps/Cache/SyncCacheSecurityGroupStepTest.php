<?php

use Aws\Result;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncCacheSecurityGroupStep;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'cache' => ['store' => 'redis'],
    ]);
});

it('skips when cache.store is not redis', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);

    expect((new SyncCacheSecurityGroupStep())([]))->toBe(StepResult::SKIPPED);
});

it('creates the cache security group — the group only, no app ingress', function (): void {
    $captured = [];

    bindMockEc2Client([
        'DescribeSecurityGroups' => [
            new Result(['SecurityGroups' => []]),  // first lookup → not found → create
            new Result(['SecurityGroups' => [['GroupName' => 'yolo-testing-cache-security-group', 'GroupId' => 'sg-cache1']]]),
        ],
        'DescribeVpcs' => new Result(['Vpcs' => [['VpcId' => 'vpc-1']]]),
        'CreateSecurityGroup' => new Result(['GroupId' => 'sg-cache1']),
    ], $captured);

    expect((new SyncCacheSecurityGroupStep())([]))->toBe(StepResult::CREATED);

    // The ingress (6379 from the task SG) is the app's concern, authorised by
    // AuthoriseCacheIngressStep — never here.
    expect(array_column($captured, 'name'))
        ->toContain('CreateSecurityGroup')
        ->not->toContain('AuthorizeSecurityGroupIngress');
});
