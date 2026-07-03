<?php

declare(strict_types=1);

use Aws\Result;
use Codinglabs\Yolo\Resources\Rds\RdsSubnet;

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);
});

it('names the group after the private tier', function (): void {
    expect((new RdsSubnet())->name())->toBe('yolo-testing-private-subnet-group');
});

it('spans only the private subnets', function (): void {
    $ec2Captured = [];
    bindMockEc2Client([
        // One name-tag lookup per private subnet, in AZ order.
        'DescribeSubnets' => [
            new Result(['Subnets' => [['SubnetId' => 'subnet-pa']]]),
            new Result(['Subnets' => [['SubnetId' => 'subnet-pb']]]),
            new Result(['Subnets' => [['SubnetId' => 'subnet-pc']]]),
        ],
    ], $ec2Captured);

    $rdsCaptured = [];
    bindMockRdsClient(['CreateDBSubnetGroup' => new Result()], $rdsCaptured);

    (new RdsSubnet())->create();

    $create = collect($rdsCaptured)->firstWhere('name', 'CreateDBSubnetGroup');
    expect($create['args']['DBSubnetGroupName'])->toBe('yolo-testing-private-subnet-group')
        ->and($create['args']['SubnetIds'])->toBe(['subnet-pa', 'subnet-pb', 'subnet-pc']);
});
