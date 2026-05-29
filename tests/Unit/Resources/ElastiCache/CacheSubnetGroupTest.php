<?php

use Aws\Result;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\ElastiCache\CacheSubnetGroup;

beforeEach(function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2', 'cache' => true],
    ]);
});

it('is env-scoped and named without the app segment', function () {
    $resource = new CacheSubnetGroup();

    expect($resource->scope())->toBe(Scope::Env);
    expect($resource->name())->toBe('yolo-testing-cache-subnet-group');
    expect($resource->tags())->toMatchArray([
        'Name' => 'yolo-testing-cache-subnet-group',
        'yolo:scope' => 'env',
    ]);
    expect($resource->tags())->not->toHaveKey('yolo:app');
});

it('creates the subnet group across every VPC subnet', function () {
    $ec2 = [];
    $captured = [];

    bindMockEc2Client([
        'DescribeVpcs' => new Result(['Vpcs' => [['VpcId' => 'vpc-1']]]),
        'DescribeSubnets' => new Result(['Subnets' => [
            ['SubnetId' => 'subnet-a'],
            ['SubnetId' => 'subnet-b'],
            ['SubnetId' => 'subnet-c'],
        ]]),
    ], $ec2);

    bindMockElastiCacheClient([
        'CreateCacheSubnetGroup' => new Result(),
    ], $captured);

    (new CacheSubnetGroup())->create();

    $call = collect($captured)->firstWhere('name', 'CreateCacheSubnetGroup');

    expect($call['args']['CacheSubnetGroupName'])->toBe('yolo-testing-cache-subnet-group');
    expect($call['args']['SubnetIds'])->toBe(['subnet-a', 'subnet-b', 'subnet-c']);
});
