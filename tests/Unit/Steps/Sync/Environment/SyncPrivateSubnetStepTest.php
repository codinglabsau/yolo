<?php

declare(strict_types=1);

use Aws\Result;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncPrivateSubnetAStep;

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);
});

it('surfaces the planned /24 as a change before the subnet exists', function (): void {
    $captured = [];
    bindMockEc2Client([
        'DescribeSubnets' => new Result(['Subnets' => []]),
        'DescribeVpcs' => new Result(['Vpcs' => [['CidrBlock' => '10.7.0.0/16', 'VpcId' => 'vpc-7']]]),
    ], $captured);

    $step = new SyncPrivateSubnetAStep();

    expect($step(['dry-run' => true]))->toBe(StepResult::WOULD_CREATE)
        ->and(collect($step->changes())->first()->to)->toBe('10.7.10.0/24')
        ->and(collect($captured)->pluck('name'))->not->toContain('CreateSubnet');
});

it('survives a greenfield plan pass — no VPC yet, the carve derives from the /16 the VPC sync will claim', function (): void {
    $captured = [];
    bindMockEc2Client([
        // Nothing exists: no subnets, no VPCs anywhere in the region.
        'DescribeSubnets' => new Result(['Subnets' => []]),
        'DescribeVpcs' => new Result(['Vpcs' => []]),
    ], $captured);

    $step = new SyncPrivateSubnetAStep();

    expect($step(['dry-run' => true]))->toBe(StepResult::WOULD_CREATE)
        ->and(collect($step->changes())->first()->to)->toBe('10.1.10.0/24');
});
