<?php

declare(strict_types=1);

use Aws\Result;
use Codinglabs\Yolo\Resources\Ec2\PublicSubnet;

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);
});

it('carves each subnet a /24 from whichever /16 the VPC was allocated', function (int $index): void {
    $captured = [];
    bindMockEc2Client([
        'DescribeAvailabilityZones' => new Result(['AvailabilityZones' => [
            ['ZoneName' => 'ap-southeast-2a'],
            ['ZoneName' => 'ap-southeast-2b'],
            ['ZoneName' => 'ap-southeast-2c'],
        ]]),
        'DescribeVpcs' => new Result(['Vpcs' => [['CidrBlock' => '10.7.0.0/16', 'VpcId' => 'vpc-7']]]),
        'CreateSubnet' => new Result(),
    ], $captured);

    (new PublicSubnet($index))->create();

    $create = collect($captured)->firstWhere('name', 'CreateSubnet');
    expect($create['args']['CidrBlock'])->toBe("10.7.{$index}.0/24")
        ->and($create['args']['VpcId'])->toBe('vpc-7')
        ->and($create['args']['AvailabilityZone'])->toBe(['ap-southeast-2a', 'ap-southeast-2b', 'ap-southeast-2c'][$index])
        ->and($create['args']['MapPublicIpOnLaunch'])->toBeTrue();
})->with([0, 1, 2]);
