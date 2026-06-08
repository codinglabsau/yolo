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

// ── Default route ────────────────────────────────────────────────────────────

it('creates the default route when the route table lacks one', function () {
    $captured = [];
    bindMockEc2Client([
        'DescribeRouteTables' => routeTableResult(routes: [
            ['DestinationCidrBlock' => '10.0.0.0/16', 'GatewayId' => 'local'],
        ]),
        'DescribeInternetGateways' => new Result(['InternetGateways' => [['InternetGatewayId' => 'igw-123']]]),
    ], $captured);

    expect((new SyncDefaultRouteStep())([]))->toBe(StepResult::SYNCED);

    $create = collect($captured)->firstWhere('name', 'CreateRoute');
    expect($create)->not->toBeNull();
    expect($create['args'])->toMatchArray([
        'DestinationCidrBlock' => '0.0.0.0/0',
        'GatewayId' => 'igw-123',
        'RouteTableId' => 'rtb-1',
    ]);
});

it('is in sync when the default route already exists', function () {
    $captured = [];
    bindMockEc2Client([
        'DescribeRouteTables' => routeTableResult(routes: [
            ['DestinationCidrBlock' => '10.0.0.0/16', 'GatewayId' => 'local'],
            ['DestinationCidrBlock' => '0.0.0.0/0', 'GatewayId' => 'igw-123'],
        ]),
    ], $captured);

    $step = new SyncDefaultRouteStep();
    expect($step([]))->toBe(StepResult::SYNCED);
    expect($step->changes())->toBeEmpty();
    expect(array_column($captured, 'name'))->not->toContain('CreateRoute');
});

it('records the missing default route on the plan pass without creating it', function () {
    $captured = [];
    bindMockEc2Client([
        'DescribeRouteTables' => routeTableResult(routes: [
            ['DestinationCidrBlock' => '10.0.0.0/16', 'GatewayId' => 'local'],
        ]),
    ], $captured);

    $step = new SyncDefaultRouteStep();
    expect($step(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC);
    expect($step->changes())->not->toBeEmpty();
    expect(array_column($captured, 'name'))->not->toContain('CreateRoute');
});

// ── Public subnet associations ───────────────────────────────────────────────

it('associates only the public subnets that are not yet attached', function () {
    $captured = [];
    bindMockEc2Client([
        // subnet-a is already associated (plus the main association); b and c are not.
        'DescribeRouteTables' => routeTableResult(associations: [
            ['Main' => true],
            ['SubnetId' => 'subnet-a'],
        ]),
        'DescribeSubnets' => [
            new Result(['Subnets' => [['SubnetId' => 'subnet-a']]]),
            new Result(['Subnets' => [['SubnetId' => 'subnet-b']]]),
            new Result(['Subnets' => [['SubnetId' => 'subnet-c']]]),
        ],
    ], $captured);

    expect((new SyncPublicSubnetsAssociationToRouteTableStep())([]))->toBe(StepResult::SYNCED);

    $associated = collect($captured)
        ->where('name', 'AssociateRouteTable')
        ->pluck('args.SubnetId');

    expect($associated)->toContain('subnet-b', 'subnet-c');
    expect($associated)->not->toContain('subnet-a');
});

it('is in sync when every public subnet is already associated', function () {
    $captured = [];
    bindMockEc2Client([
        'DescribeRouteTables' => routeTableResult(associations: [
            ['Main' => true],
            ['SubnetId' => 'subnet-a'],
            ['SubnetId' => 'subnet-b'],
            ['SubnetId' => 'subnet-c'],
        ]),
        'DescribeSubnets' => [
            new Result(['Subnets' => [['SubnetId' => 'subnet-a']]]),
            new Result(['Subnets' => [['SubnetId' => 'subnet-b']]]),
            new Result(['Subnets' => [['SubnetId' => 'subnet-c']]]),
        ],
    ], $captured);

    $step = new SyncPublicSubnetsAssociationToRouteTableStep();
    expect($step([]))->toBe(StepResult::SYNCED);
    expect($step->changes())->toBeEmpty();
    expect(array_column($captured, 'name'))->not->toContain('AssociateRouteTable');
});

it('records the missing associations on the plan pass without associating', function () {
    $captured = [];
    bindMockEc2Client([
        'DescribeRouteTables' => routeTableResult(associations: [['Main' => true]]),
        'DescribeSubnets' => [
            new Result(['Subnets' => [['SubnetId' => 'subnet-a']]]),
            new Result(['Subnets' => [['SubnetId' => 'subnet-b']]]),
            new Result(['Subnets' => [['SubnetId' => 'subnet-c']]]),
        ],
    ], $captured);

    $step = new SyncPublicSubnetsAssociationToRouteTableStep();
    expect($step(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC);
    expect($step->changes())->toHaveCount(3);
    expect(array_column($captured, 'name'))->not->toContain('AssociateRouteTable');
});
