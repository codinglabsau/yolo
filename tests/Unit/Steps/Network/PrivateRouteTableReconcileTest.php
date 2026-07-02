<?php

declare(strict_types=1);

use Aws\Result;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncPrivateRouteTableStep;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncPrivateSubnetsAssociationToRouteTableStep;

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);
});

function privateRouteTableResult(array $associations = []): Result
{
    return new Result(['RouteTables' => [[
        'RouteTableId' => 'rtb-private',
        'Associations' => $associations,
    ]]]);
}

/** The three private subnets, resolved in index order off the DescribeSubnets queue. */
function privateSubnetResults(): array
{
    return [
        new Result(['Subnets' => [['SubnetId' => 'subnet-pa']]]),
        new Result(['Subnets' => [['SubnetId' => 'subnet-pb']]]),
        new Result(['Subnets' => [['SubnetId' => 'subnet-pc']]]),
    ];
}

// ── Private route table ──────────────────────────────────────────────────────

it('creates the private route table with no default route', function (): void {
    $captured = [];
    bindMockEc2Client([
        'DescribeRouteTables' => new Result(['RouteTables' => []]),
        'DescribeVpcs' => new Result(['Vpcs' => [['VpcId' => 'vpc-7']]]),
        'CreateRouteTable' => new Result(),
    ], $captured);

    expect((new SyncPrivateRouteTableStep())([]))->toBe(StepResult::CREATED);

    $create = collect($captured)->firstWhere('name', 'CreateRouteTable');
    expect($create['args']['VpcId'])->toBe('vpc-7')
        ->and(collect($captured)->pluck('name'))->not->toContain('CreateRoute');
});

it('reports WOULD_CREATE for the private route table on the plan pass', function (): void {
    $captured = [];
    bindMockEc2Client(['DescribeRouteTables' => new Result(['RouteTables' => []])], $captured);

    expect((new SyncPrivateRouteTableStep())(['dry-run' => true]))->toBe(StepResult::WOULD_CREATE)
        ->and(collect($captured)->pluck('name'))->not->toContain('CreateRouteTable');
});

it('leaves adopted private subnets to their own routing', function (): void {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'private-subnets' => ['their-private-a', 'their-private-b', 'their-private-c'],
    ]);

    $captured = [];
    bindMockEc2Client([], $captured);

    expect((new SyncPrivateRouteTableStep())(['dry-run' => true]))->toBe(StepResult::CUSTOM_MANAGED)
        ->and((new SyncPrivateSubnetsAssociationToRouteTableStep())(['dry-run' => true]))->toBe(StepResult::CUSTOM_MANAGED)
        ->and($captured)->toBeEmpty();
});

// ── Private subnet associations ──────────────────────────────────────────────

it('honours the reconciler contract for the private subnet associations', function (): void {
    assertSyncStepReconciles(
        makeStep: fn (): SyncPrivateSubnetsAssociationToRouteTableStep => new SyncPrivateSubnetsAssociationToRouteTableStep(),
        bindInSync: function (array &$captured): void {
            bindMockEc2Client([
                'DescribeRouteTables' => privateRouteTableResult(associations: [
                    ['SubnetId' => 'subnet-pa'],
                    ['SubnetId' => 'subnet-pb'],
                    ['SubnetId' => 'subnet-pc'],
                ]),
                'DescribeSubnets' => privateSubnetResults(),
            ], $captured);
        },
        bindDrifted: function (array &$captured): void {
            bindMockEc2Client([
                'DescribeRouteTables' => privateRouteTableResult(),
                'DescribeSubnets' => privateSubnetResults(),
            ], $captured);
        },
        writeCommand: 'AssociateRouteTable',
    );
});

it('associates only the private subnets that are not yet attached', function (): void {
    $captured = [];
    bindMockEc2Client([
        'DescribeRouteTables' => privateRouteTableResult(associations: [
            ['SubnetId' => 'subnet-pa'],
        ]),
        'DescribeSubnets' => privateSubnetResults(),
    ], $captured);

    expect((new SyncPrivateSubnetsAssociationToRouteTableStep())([]))->toBe(StepResult::SYNCED);

    $associated = collect($captured)
        ->where('name', 'AssociateRouteTable')
        ->pluck('args.SubnetId');

    expect($associated)->toContain('subnet-pb', 'subnet-pc');
    expect($associated)->not->toContain('subnet-pa');
});
