<?php

use Aws\Result;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncDefaultRouteStep;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncPublicSubnetsAssociationToRouteTableStep;

beforeEach(function () {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);
});

function routeTableResult(array $routes = [], array $associations = []): Result
{
    return new Result(['RouteTables' => [[
        'RouteTableId' => 'rtb-1',
        'Routes' => $routes,
        'Associations' => $associations,
    ]]]);
}

/** The three public subnets, resolved in index order off the DescribeSubnets queue. */
function publicSubnetResults(): array
{
    return [
        new Result(['Subnets' => [['SubnetId' => 'subnet-a']]]),
        new Result(['Subnets' => [['SubnetId' => 'subnet-b']]]),
        new Result(['Subnets' => [['SubnetId' => 'subnet-c']]]),
    ];
}

// ── Default route ────────────────────────────────────────────────────────────

it('honours the reconciler contract for the default route', function () {
    assertSyncStepReconciles(
        makeStep: fn () => new SyncDefaultRouteStep(),
        bindInSync: function (array &$captured) {
            bindMockEc2Client([
                'DescribeRouteTables' => routeTableResult(routes: [
                    ['DestinationCidrBlock' => '0.0.0.0/0', 'GatewayId' => 'igw-123'],
                ]),
            ], $captured);
        },
        bindDrifted: function (array &$captured) {
            bindMockEc2Client([
                'DescribeRouteTables' => routeTableResult(routes: [
                    ['DestinationCidrBlock' => '10.0.0.0/16', 'GatewayId' => 'local'],
                ]),
                'DescribeInternetGateways' => new Result(['InternetGateways' => [['InternetGatewayId' => 'igw-123']]]),
            ], $captured);
        },
        writeCommand: 'CreateRoute',
    );
});

it('creates the default route pointed at the internet gateway and route table', function () {
    $captured = [];
    bindMockEc2Client([
        'DescribeRouteTables' => routeTableResult(routes: [
            ['DestinationCidrBlock' => '10.0.0.0/16', 'GatewayId' => 'local'],
        ]),
        'DescribeInternetGateways' => new Result(['InternetGateways' => [['InternetGatewayId' => 'igw-123']]]),
    ], $captured);

    expect((new SyncDefaultRouteStep())([]))->toBe(StepResult::SYNCED);

    $create = collect($captured)->firstWhere('name', 'CreateRoute');
    expect($create['args'])->toMatchArray([
        'DestinationCidrBlock' => '0.0.0.0/0',
        'GatewayId' => 'igw-123',
        'RouteTableId' => 'rtb-1',
    ]);
});

// ── Public subnet associations ───────────────────────────────────────────────

it('honours the reconciler contract for the public subnet associations', function () {
    assertSyncStepReconciles(
        makeStep: fn () => new SyncPublicSubnetsAssociationToRouteTableStep(),
        bindInSync: function (array &$captured) {
            bindMockEc2Client([
                'DescribeRouteTables' => routeTableResult(associations: [
                    ['Main' => true],
                    ['SubnetId' => 'subnet-a'],
                    ['SubnetId' => 'subnet-b'],
                    ['SubnetId' => 'subnet-c'],
                ]),
                'DescribeSubnets' => publicSubnetResults(),
            ], $captured);
        },
        bindDrifted: function (array &$captured) {
            bindMockEc2Client([
                'DescribeRouteTables' => routeTableResult(associations: [['Main' => true]]),
                'DescribeSubnets' => publicSubnetResults(),
            ], $captured);
        },
        writeCommand: 'AssociateRouteTable',
    );
});

it('associates only the public subnets that are not yet attached', function () {
    $captured = [];
    bindMockEc2Client([
        // subnet-a is already associated (plus the main association); b and c are not.
        'DescribeRouteTables' => routeTableResult(associations: [
            ['Main' => true],
            ['SubnetId' => 'subnet-a'],
        ]),
        'DescribeSubnets' => publicSubnetResults(),
    ], $captured);

    expect((new SyncPublicSubnetsAssociationToRouteTableStep())([]))->toBe(StepResult::SYNCED);

    $associated = collect($captured)
        ->where('name', 'AssociateRouteTable')
        ->pluck('args.SubnetId');

    expect($associated)->toContain('subnet-b', 'subnet-c');
    expect($associated)->not->toContain('subnet-a');
});
