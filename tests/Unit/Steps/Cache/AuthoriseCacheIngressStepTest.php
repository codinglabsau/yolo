<?php

use Aws\Result;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\App\AuthoriseCacheIngressStep;

function describeCacheAndTaskGroups(): Result
{
    return new Result([
        'SecurityGroups' => [
            ['GroupName' => 'yolo-testing-cache-security-group', 'GroupId' => 'sg-cache1'],
            ['GroupName' => 'yolo-testing-my-app-ecs-task-security-group', 'GroupId' => 'sg-task456'],
        ],
    ]);
}

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'cache' => ['store' => 'redis'],
    ]);
});

it('skips when cache.store is not redis', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
    ]);

    expect((new AuthoriseCacheIngressStep())([]))->toBe(StepResult::SKIPPED);
});

it('skips while the cache security group is not provisioned yet', function (): void {
    $captured = [];
    bindMockEc2Client(['DescribeSecurityGroups' => new Result(['SecurityGroups' => []])], $captured);

    expect((new AuthoriseCacheIngressStep())([]))->toBe(StepResult::SKIPPED)
        ->and(array_column($captured, 'name'))->not->toContain('AuthorizeSecurityGroupIngress');
});

it('additively authorises 6379 from the task SG on the cache SG', function (): void {
    $captured = [];

    bindMockEc2Client([
        'DescribeSecurityGroups' => describeCacheAndTaskGroups(),
        'DescribeSecurityGroupRules' => new Result(['SecurityGroupRules' => []]),
        'AuthorizeSecurityGroupIngress' => new Result(),
    ], $captured);

    expect((new AuthoriseCacheIngressStep())([]))->toBe(StepResult::SYNCED);

    $authorise = collect($captured)->firstWhere('name', 'AuthorizeSecurityGroupIngress');
    $permission = $authorise['args']['IpPermissions'][0];

    expect($permission['FromPort'])->toBe(6379)
        ->and($permission['ToPort'])->toBe(6379)
        ->and($permission['UserIdGroupPairs'][0]['GroupId'])->toBe('sg-task456')
        ->and($authorise['args']['GroupId'])->toBe('sg-cache1');
});

it('does not authorise again when a matching 6379 rule already exists', function (): void {
    $captured = [];

    bindMockEc2Client([
        'DescribeSecurityGroups' => describeCacheAndTaskGroups(),
        'DescribeSecurityGroupRules' => new Result(['SecurityGroupRules' => [
            [
                'IsEgress' => false,
                'IpProtocol' => 'tcp',
                'FromPort' => 6379,
                'ToPort' => 6379,
                'ReferencedGroupInfo' => ['GroupId' => 'sg-task456'],
            ],
        ]]),
    ], $captured);

    expect((new AuthoriseCacheIngressStep())([]))->toBe(StepResult::SYNCED)
        ->and(array_column($captured, 'name'))->not->toContain('AuthorizeSecurityGroupIngress');
});

it('does not authorise during a dry-run', function (): void {
    $captured = [];

    bindMockEc2Client([
        'DescribeSecurityGroups' => describeCacheAndTaskGroups(),
        'DescribeSecurityGroupRules' => new Result(['SecurityGroupRules' => []]),
    ], $captured);

    (new AuthoriseCacheIngressStep())(['dry-run' => true]);

    expect(array_column($captured, 'name'))->not->toContain('AuthorizeSecurityGroupIngress');
});
