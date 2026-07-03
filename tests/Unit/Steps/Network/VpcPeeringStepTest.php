<?php

declare(strict_types=1);

use Aws\Result;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncVpcPeeringStep;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncVpcPeeringRoutesStep;

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);
});

function declarePeering(array $peerVpcIds): void
{
    $body = $peerVpcIds === []
        ? "peering: []\n"
        : "peering:\n" . implode('', array_map(fn (string $id): string => "  - {$id}\n", $peerVpcIds));

    $captured = [];
    bindRoutedS3Client([
        'GetObject' => new Result(['Body' => $body]),
    ], $captured);
}

// ── The connection set ───────────────────────────────────────────────────────

it('plans a declared peering as WOULD_CREATE and writes nothing', function (): void {
    declarePeering(['vpc-0abc123']);

    $captured = [];
    bindMockEc2Client([
        'DescribeVpcPeeringConnections' => new Result(['VpcPeeringConnections' => []]),
    ], $captured);

    expect((new SyncVpcPeeringStep())(['dry-run' => true]))->toBe(StepResult::WOULD_CREATE)
        ->and(collect($captured)->pluck('name'))->not->toContain('CreateVpcPeeringConnection');
});

it('creates, accepts and DNS-enables a declared peering on apply', function (): void {
    declarePeering(['vpc-0abc123']);

    $captured = [];
    bindMockEc2Client([
        'DescribeVpcs' => new Result(['Vpcs' => [['VpcId' => 'vpc-env']]]),
        // exists() lookups miss; the post-create reconcile finds it pending.
        'DescribeVpcPeeringConnections' => [
            new Result(['VpcPeeringConnections' => []]),
            new Result(['VpcPeeringConnections' => [[
                'VpcPeeringConnectionId' => 'pcx-1',
                'Status' => ['Code' => 'pending-acceptance'],
            ]]]),
        ],
        'CreateVpcPeeringConnection' => new Result(),
        'AcceptVpcPeeringConnection' => new Result(),
        'ModifyVpcPeeringConnectionOptions' => new Result(),
    ], $captured);

    expect((new SyncVpcPeeringStep())([]))->toBe(StepResult::CREATED)
        ->and(collect($captured)->pluck('name'))->toContain('CreateVpcPeeringConnection', 'AcceptVpcPeeringConnection', 'ModifyVpcPeeringConnectionOptions');
});

it('tears down a live YOLO peering the manifest no longer declares', function (): void {
    declarePeering([]);

    $captured = [];
    bindMockEc2Client([
        'DescribeVpcPeeringConnections' => new Result(['VpcPeeringConnections' => [[
            'VpcPeeringConnectionId' => 'pcx-old',
            'Status' => ['Code' => 'active'],
            'AccepterVpcInfo' => ['VpcId' => 'vpc-retired'],
        ]]]),
        'DeleteVpcPeeringConnection' => new Result(),
    ], $captured);

    $step = new SyncVpcPeeringStep();

    expect($step(['dry-run' => true]))->toBe(StepResult::WOULD_DELETE)
        ->and(collect($captured)->pluck('name'))->not->toContain('DeleteVpcPeeringConnection');

    expect((new SyncVpcPeeringStep())([]))->toBe(StepResult::DELETED);
});

it('skips cleanly when nothing is declared and nothing is live', function (): void {
    declarePeering([]);

    $captured = [];
    bindMockEc2Client([
        'DescribeVpcPeeringConnections' => new Result(['VpcPeeringConnections' => []]),
    ], $captured);

    expect((new SyncVpcPeeringStep())(['dry-run' => true]))->toBe(StepResult::SKIPPED);
});

// ── The routes ───────────────────────────────────────────────────────────────

/** Route tables for [public lookup, peer main lookup, blackhole re-lookup]. */
function peeringRouteTables(array $publicRoutes, array $peerRoutes): array
{
    $publicRouteTable = new Result(['RouteTables' => [['RouteTableId' => 'rtb-public', 'Routes' => $publicRoutes]]]);

    return [
        $publicRouteTable,
        new Result(['RouteTables' => [['RouteTableId' => 'rtb-peer-main', 'Routes' => $peerRoutes]]]),
        $publicRouteTable,
    ];
}

it('honours the reconciler contract for the peering routes', function (): void {
    assertSyncStepReconciles(
        makeStep: fn (): SyncVpcPeeringRoutesStep => new SyncVpcPeeringRoutesStep(),
        bindInSync: function (array &$captured): void {
            declarePeering(['vpc-0abc123']);
            bindMockEc2Client([
                'DescribeVpcs' => [
                    new Result(['Vpcs' => [['VpcId' => 'vpc-env', 'CidrBlock' => '10.1.0.0/16']]]),
                    new Result(['Vpcs' => [['VpcId' => 'vpc-0abc123', 'CidrBlock' => '172.31.0.0/16']]]),
                ],
                'DescribeRouteTables' => peeringRouteTables(
                    publicRoutes: [['DestinationCidrBlock' => '172.31.0.0/16', 'VpcPeeringConnectionId' => 'pcx-1', 'State' => 'active']],
                    peerRoutes: [['DestinationCidrBlock' => '10.1.0.0/16', 'VpcPeeringConnectionId' => 'pcx-1', 'State' => 'active']],
                ),
            ], $captured);
        },
        bindDrifted: function (array &$captured): void {
            declarePeering(['vpc-0abc123']);
            bindMockEc2Client([
                'DescribeVpcs' => [
                    new Result(['Vpcs' => [['VpcId' => 'vpc-env', 'CidrBlock' => '10.1.0.0/16']]]),
                    new Result(['Vpcs' => [['VpcId' => 'vpc-0abc123', 'CidrBlock' => '172.31.0.0/16']]]),
                ],
                'DescribeRouteTables' => peeringRouteTables(publicRoutes: [], peerRoutes: []),
                'DescribeVpcPeeringConnections' => new Result(['VpcPeeringConnections' => [[
                    'VpcPeeringConnectionId' => 'pcx-1',
                    'Status' => ['Code' => 'active'],
                ]]]),
                'CreateRoute' => new Result(),
            ], $captured);
        },
        writeCommand: 'CreateRoute',
    );
});

it('writes both directions on apply — peer CIDR outbound, env CIDR back through the peer main table', function (): void {
    declarePeering(['vpc-0abc123']);

    $captured = [];
    bindMockEc2Client([
        'DescribeVpcs' => [
            new Result(['Vpcs' => [['VpcId' => 'vpc-env', 'CidrBlock' => '10.1.0.0/16']]]),
            new Result(['Vpcs' => [['VpcId' => 'vpc-0abc123', 'CidrBlock' => '172.31.0.0/16']]]),
        ],
        'DescribeRouteTables' => peeringRouteTables(publicRoutes: [], peerRoutes: []),
        'DescribeVpcPeeringConnections' => new Result(['VpcPeeringConnections' => [[
            'VpcPeeringConnectionId' => 'pcx-1',
            'Status' => ['Code' => 'active'],
        ]]]),
        'CreateRoute' => new Result(),
    ], $captured);

    expect((new SyncVpcPeeringRoutesStep())([]))->toBe(StepResult::SYNCED);

    $routes = collect($captured)->where('name', 'CreateRoute')->values();
    expect($routes)->toHaveCount(2)
        ->and($routes[0]['args'])->toMatchArray(['DestinationCidrBlock' => '172.31.0.0/16', 'RouteTableId' => 'rtb-public', 'VpcPeeringConnectionId' => 'pcx-1'])
        ->and($routes[1]['args'])->toMatchArray(['DestinationCidrBlock' => '10.1.0.0/16', 'RouteTableId' => 'rtb-peer-main', 'VpcPeeringConnectionId' => 'pcx-1']);
});

it('prunes blackhole peering routes a torn-down connection left behind', function (): void {
    declarePeering([]);

    $captured = [];
    bindMockEc2Client([
        'DescribeVpcs' => new Result(['Vpcs' => [['VpcId' => 'vpc-env', 'CidrBlock' => '10.1.0.0/16']]]),
        'DescribeRouteTables' => new Result(['RouteTables' => [['RouteTableId' => 'rtb-public', 'Routes' => [
            ['DestinationCidrBlock' => '172.31.0.0/16', 'VpcPeeringConnectionId' => 'pcx-gone', 'State' => 'blackhole'],
        ]]]]),
        'DeleteRoute' => new Result(),
    ], $captured);

    expect((new SyncVpcPeeringRoutesStep())([]))->toBe(StepResult::SYNCED);

    $delete = collect($captured)->firstWhere('name', 'DeleteRoute');
    expect($delete['args'])->toMatchArray(['RouteTableId' => 'rtb-public', 'DestinationCidrBlock' => '172.31.0.0/16']);
});

it('reports a declared peer VPC that does not exist as pending, never throwing', function (): void {
    declarePeering(['vpc-0abc123']);

    $captured = [];
    bindMockEc2Client([
        'DescribeVpcs' => [
            new Result(['Vpcs' => [['VpcId' => 'vpc-env', 'CidrBlock' => '10.1.0.0/16']]]),
            new Result(['Vpcs' => []]),
        ],
        'DescribeRouteTables' => new Result(['RouteTables' => [['RouteTableId' => 'rtb-public', 'Routes' => []]]]),
    ], $captured);

    $step = new SyncVpcPeeringRoutesStep();

    expect($step(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC)
        ->and(collect($step->changes())->first()->to)->toContain('peer VPC not found');
});
