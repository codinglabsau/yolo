<?php

declare(strict_types=1);

use Aws\Result;
use Codinglabs\Yolo\Resources\Ec2\PrivateSubnet;

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);
});

/** @return array<string, Result> */
function privateSubnetCreateWorld(): array
{
    return [
        'DescribeAvailabilityZones' => new Result(['AvailabilityZones' => [
            ['ZoneName' => 'ap-southeast-2a'],
            ['ZoneName' => 'ap-southeast-2b'],
            ['ZoneName' => 'ap-southeast-2c'],
        ]]),
        'DescribeVpcs' => new Result(['Vpcs' => [['CidrBlock' => '10.7.0.0/16', 'VpcId' => 'vpc-7']]]),
        'DescribeSubnets' => new Result(['Subnets' => []]),
        'CreateSubnet' => new Result(),
    ];
}

it('carves each private subnet a /24 offset past the public tier on a YOLO VPC', function (int $index): void {
    $captured = [];
    bindMockEc2Client(privateSubnetCreateWorld(), $captured);

    (new PrivateSubnet($index))->create();

    $offsetOctet = 10 + $index;
    $create = collect($captured)->firstWhere('name', 'CreateSubnet');
    expect($create['args']['CidrBlock'])->toBe("10.7.{$offsetOctet}.0/24")
        ->and($create['args']['VpcId'])->toBe('vpc-7')
        ->and($create['args']['AvailabilityZone'])->toBe(['ap-southeast-2a', 'ap-southeast-2b', 'ap-southeast-2c'][$index])
        ->and($create['args'])->not->toHaveKey('MapPublicIpOnLaunch');
})->with([0, 1, 2]);

it('discovers the index-th free /24 inside an adopted VPC', function (): void {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'vpc' => 'custom-vpc',
    ]);

    $captured = [];
    bindMockEc2Client([
        ...privateSubnetCreateWorld(),
        'DescribeVpcs' => new Result(['Vpcs' => [['CidrBlock' => '172.31.0.0/16', 'VpcId' => 'vpc-custom']]]),
        // The owner's first two /24s are taken, so the free run starts at .2.
        'DescribeSubnets' => new Result(['Subnets' => [
            ['CidrBlock' => '172.31.0.0/24'],
            ['CidrBlock' => '172.31.1.0/24'],
        ]]),
    ], $captured);

    (new PrivateSubnet(1))->create();

    $create = collect($captured)->firstWhere('name', 'CreateSubnet');
    expect($create['args']['CidrBlock'])->toBe('172.31.3.0/24')
        ->and($create['args']['VpcId'])->toBe('vpc-custom');
});

it('ignores its own tier when discovering, so an already-created sibling does not shift the carve', function (): void {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'vpc' => 'custom-vpc',
    ]);

    $captured = [];
    bindMockEc2Client([
        ...privateSubnetCreateWorld(),
        'DescribeVpcs' => new Result(['Vpcs' => [['CidrBlock' => '172.31.0.0/16', 'VpcId' => 'vpc-custom']]]),
        // Subnet A was created earlier in this apply (172.31.1.0/24) — excluded
        // from "in use" by its Name tag, so B still lands on the same candidate
        // the plan pass showed it.
        'DescribeSubnets' => new Result(['Subnets' => [
            ['CidrBlock' => '172.31.0.0/24'],
            ['CidrBlock' => '172.31.1.0/24', 'Tags' => [['Key' => 'Name', 'Value' => 'yolo-testing-private-subnet-a']]],
        ]]),
    ], $captured);

    (new PrivateSubnet(1))->create();

    $create = collect($captured)->firstWhere('name', 'CreateSubnet');
    expect($create['args']['CidrBlock'])->toBe('172.31.2.0/24');
});

it('resolves adopted names from the private-subnets manifest key', function (int $index): void {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'private-subnets' => ['their-private-a', 'their-private-b', 'their-private-c'],
    ]);

    expect((new PrivateSubnet($index))->name())
        ->toBe(['their-private-a', 'their-private-b', 'their-private-c'][$index]);
})->with([0, 1, 2]);
