<?php

declare(strict_types=1);

use Aws\Result;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Commands\SyncEnvironmentCommand;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncVpcPeeringStep;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncVpcPeeringDnsStep;
use Codinglabs\Yolo\Steps\Sync\Environment\SyncVpcPeeringRoutesStep;
use Codinglabs\Yolo\Steps\Destroy\Environment\TeardownVpcPeeringConnectionsStep;

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

it('creates and accepts a declared peering on apply — DNS resolution waits for the DNS step', function (): void {
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
    ], $captured);

    expect((new SyncVpcPeeringStep())([]))->toBe(StepResult::CREATED)
        ->and(collect($captured)->pluck('name'))->toContain('CreateVpcPeeringConnection', 'AcceptVpcPeeringConnection')
        ->and(collect($captured)->pluck('name'))->not->toContain('ModifyVpcPeeringConnectionOptions');
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

/** DescribeRouteTables queue: [public (by name), private (by name), peer VPC (by vpc-id)]. */
function peeringRouteTables(array $publicRoutes, array $privateRoutes, array $peerRouteTables = []): array
{
    return [
        new Result(['RouteTables' => [['RouteTableId' => 'rtb-public', 'Routes' => $publicRoutes]]]),
        new Result(['RouteTables' => [['RouteTableId' => 'rtb-private', 'Routes' => $privateRoutes]]]),
        new Result(['RouteTables' => $peerRouteTables]),
    ];
}

/** A peer route table that governs a subnet (has a subnet association). */
function subnetAssociatedPeerRouteTable(string $routeTableId, array $routes = []): array
{
    return [
        'RouteTableId' => $routeTableId,
        'Associations' => [['SubnetId' => 'subnet-peer', 'Main' => false]],
        'Routes' => $routes,
    ];
}

/** A peer main route table no subnet is associated with. */
function unassociatedPeerMainRouteTable(string $routeTableId, array $routes = []): array
{
    return [
        'RouteTableId' => $routeTableId,
        'Associations' => [['Main' => true]],
        'Routes' => $routes,
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
                    privateRoutes: [['DestinationCidrBlock' => '172.31.0.0/16', 'VpcPeeringConnectionId' => 'pcx-1', 'State' => 'active']],
                    peerRouteTables: [subnetAssociatedPeerRouteTable('rtb-peer-a', [
                        ['DestinationCidrBlock' => '10.1.0.0/16', 'VpcPeeringConnectionId' => 'pcx-1', 'State' => 'active'],
                    ])],
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
                'DescribeRouteTables' => peeringRouteTables(
                    publicRoutes: [],
                    privateRoutes: [],
                    peerRouteTables: [subnetAssociatedPeerRouteTable('rtb-peer-a')],
                ),
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

it('writes the outbound route into every yolo table and the return route into the peer tables that govern subnets', function (): void {
    declarePeering(['vpc-0abc123']);

    $captured = [];
    bindMockEc2Client([
        'DescribeVpcs' => [
            new Result(['Vpcs' => [['VpcId' => 'vpc-env', 'CidrBlock' => '10.1.0.0/16']]]),
            new Result(['Vpcs' => [['VpcId' => 'vpc-0abc123', 'CidrBlock' => '172.31.0.0/16']]]),
        ],
        'DescribeRouteTables' => peeringRouteTables(
            publicRoutes: [],
            privateRoutes: [],
            // The main table has no subnet association — the route must land
            // in the tables that actually govern subnets, sorted by id.
            peerRouteTables: [
                unassociatedPeerMainRouteTable('rtb-peer-main'),
                subnetAssociatedPeerRouteTable('rtb-peer-b'),
                subnetAssociatedPeerRouteTable('rtb-peer-a'),
            ],
        ),
        'DescribeVpcPeeringConnections' => new Result(['VpcPeeringConnections' => [[
            'VpcPeeringConnectionId' => 'pcx-1',
            'Status' => ['Code' => 'active'],
        ]]]),
        'CreateRoute' => new Result(),
    ], $captured);

    expect((new SyncVpcPeeringRoutesStep())([]))->toBe(StepResult::SYNCED);

    $routes = collect($captured)->where('name', 'CreateRoute')->values();
    expect($routes)->toHaveCount(4)
        ->and($routes[0]['args'])->toMatchArray(['DestinationCidrBlock' => '172.31.0.0/16', 'RouteTableId' => 'rtb-public', 'VpcPeeringConnectionId' => 'pcx-1'])
        ->and($routes[1]['args'])->toMatchArray(['DestinationCidrBlock' => '172.31.0.0/16', 'RouteTableId' => 'rtb-private', 'VpcPeeringConnectionId' => 'pcx-1'])
        ->and($routes[2]['args'])->toMatchArray(['DestinationCidrBlock' => '10.1.0.0/16', 'RouteTableId' => 'rtb-peer-a', 'VpcPeeringConnectionId' => 'pcx-1'])
        ->and($routes[3]['args'])->toMatchArray(['DestinationCidrBlock' => '10.1.0.0/16', 'RouteTableId' => 'rtb-peer-b', 'VpcPeeringConnectionId' => 'pcx-1'])
        ->and($routes->pluck('args.RouteTableId'))->not->toContain('rtb-peer-main');
});

it('falls back to the peer main table only when no peer table has a subnet association', function (): void {
    declarePeering(['vpc-0abc123']);

    $captured = [];
    bindMockEc2Client([
        'DescribeVpcs' => [
            new Result(['Vpcs' => [['VpcId' => 'vpc-env', 'CidrBlock' => '10.1.0.0/16']]]),
            new Result(['Vpcs' => [['VpcId' => 'vpc-0abc123', 'CidrBlock' => '172.31.0.0/16']]]),
        ],
        'DescribeRouteTables' => peeringRouteTables(
            publicRoutes: [],
            privateRoutes: [],
            peerRouteTables: [unassociatedPeerMainRouteTable('rtb-peer-main')],
        ),
        'DescribeVpcPeeringConnections' => new Result(['VpcPeeringConnections' => [[
            'VpcPeeringConnectionId' => 'pcx-1',
            'Status' => ['Code' => 'active'],
        ]]]),
        'CreateRoute' => new Result(),
    ], $captured);

    expect((new SyncVpcPeeringRoutesStep())([]))->toBe(StepResult::SYNCED);

    $routes = collect($captured)->where('name', 'CreateRoute')->values();
    expect($routes)->toHaveCount(3)
        ->and($routes[2]['args'])->toMatchArray(['DestinationCidrBlock' => '10.1.0.0/16', 'RouteTableId' => 'rtb-peer-main', 'VpcPeeringConnectionId' => 'pcx-1']);
});

it('marks the return route as a foreign write in the plan', function (): void {
    declarePeering(['vpc-0abc123']);

    $captured = [];
    bindMockEc2Client([
        'DescribeVpcs' => [
            new Result(['Vpcs' => [['VpcId' => 'vpc-env', 'CidrBlock' => '10.1.0.0/16']]]),
            new Result(['Vpcs' => [['VpcId' => 'vpc-0abc123', 'CidrBlock' => '172.31.0.0/16']]]),
        ],
        'DescribeRouteTables' => peeringRouteTables(
            publicRoutes: [],
            privateRoutes: [],
            peerRouteTables: [subnetAssociatedPeerRouteTable('rtb-peer-a')],
        ),
    ], $captured);

    $step = new SyncVpcPeeringRoutesStep();

    expect($step(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC)
        ->and(collect($step->changes())->pluck('attribute'))->toContain(
            'outbound route 172.31.0.0/16 (rtb-public)',
            'outbound route 172.31.0.0/16 (rtb-private)',
            'return route 10.1.0.0/16 (peer rtb-peer-a — not yolo-managed)',
        );
});

it('emits an identical plan across consecutive runs against the same live state', function (): void {
    $bindDrifted = function (array &$captured): void {
        declarePeering(['vpc-0abc123']);
        bindMockEc2Client([
            'DescribeVpcs' => [
                new Result(['Vpcs' => [['VpcId' => 'vpc-env', 'CidrBlock' => '10.1.0.0/16']]]),
                new Result(['Vpcs' => [['VpcId' => 'vpc-0abc123', 'CidrBlock' => '172.31.0.0/16']]]),
            ],
            'DescribeRouteTables' => peeringRouteTables(
                publicRoutes: [],
                privateRoutes: [],
                peerRouteTables: [
                    subnetAssociatedPeerRouteTable('rtb-peer-b'),
                    subnetAssociatedPeerRouteTable('rtb-peer-a'),
                ],
            ),
        ], $captured);
    };

    $plans = [];

    foreach ([1, 2] as $run) {
        $captured = [];
        $bindDrifted($captured);
        $step = new SyncVpcPeeringRoutesStep();
        $step(['dry-run' => true]);
        $plans[$run] = array_map(fn (Change $change): string => $change->describe(), $step->changes());
    }

    expect($plans[1])->toBe($plans[2])->not->toBeEmpty();
});

it('prunes blackhole peering routes from both yolo tables', function (): void {
    declarePeering([]);

    $captured = [];
    bindMockEc2Client([
        'DescribeVpcs' => new Result(['Vpcs' => [['VpcId' => 'vpc-env', 'CidrBlock' => '10.1.0.0/16']]]),
        'DescribeRouteTables' => peeringRouteTables(
            publicRoutes: [['DestinationCidrBlock' => '172.31.0.0/16', 'VpcPeeringConnectionId' => 'pcx-gone', 'State' => 'blackhole']],
            privateRoutes: [['DestinationCidrBlock' => '172.31.0.0/16', 'VpcPeeringConnectionId' => 'pcx-gone', 'State' => 'blackhole']],
        ),
        'DeleteRoute' => new Result(),
    ], $captured);

    expect((new SyncVpcPeeringRoutesStep())([]))->toBe(StepResult::SYNCED);

    $deletes = collect($captured)->where('name', 'DeleteRoute')->values();
    expect($deletes)->toHaveCount(2)
        ->and($deletes[0]['args'])->toMatchArray(['RouteTableId' => 'rtb-public', 'DestinationCidrBlock' => '172.31.0.0/16'])
        ->and($deletes[1]['args'])->toMatchArray(['RouteTableId' => 'rtb-private', 'DestinationCidrBlock' => '172.31.0.0/16']);
});

it('reports a declared peer VPC that does not exist as pending, never throwing', function (): void {
    declarePeering(['vpc-0abc123']);

    $captured = [];
    bindMockEc2Client([
        'DescribeVpcs' => [
            new Result(['Vpcs' => [['VpcId' => 'vpc-env', 'CidrBlock' => '10.1.0.0/16']]]),
            new Result(['Vpcs' => []]),
        ],
        'DescribeRouteTables' => peeringRouteTables(publicRoutes: [], privateRoutes: []),
    ], $captured);

    $step = new SyncVpcPeeringRoutesStep();

    expect($step(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC)
        ->and(collect($step->changes())->first()->to)->toContain('peer VPC not found');
});

// ── DNS resolution — the last peering act ────────────────────────────────────

it('orders DNS enablement after the routes step in sync:environment', function (): void {
    $environmentSteps = (new SyncEnvironmentCommand())->scopes()['environment'];

    expect(array_search(SyncVpcPeeringDnsStep::class, $environmentSteps, true))
        ->toBeGreaterThan(array_search(SyncVpcPeeringRoutesStep::class, $environmentSteps, true));
});

it('plans DNS enablement for a peering that does not resolve yet, without writing', function (): void {
    declarePeering(['vpc-0abc123']);

    $captured = [];
    bindMockEc2Client([
        'DescribeVpcPeeringConnections' => new Result(['VpcPeeringConnections' => [[
            'VpcPeeringConnectionId' => 'pcx-1',
            'Status' => ['Code' => 'active'],
        ]]]),
    ], $captured);

    $step = new SyncVpcPeeringDnsStep();

    expect($step(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC)
        ->and($step->changes()[0]->describe())->toBe('DNS resolution over peering (vpc-0abc123): false → true')
        ->and(collect($captured)->pluck('name'))->not->toContain('ModifyVpcPeeringConnectionOptions');
});

it('enables DNS resolution both ways on apply', function (): void {
    declarePeering(['vpc-0abc123']);

    $captured = [];
    bindMockEc2Client([
        'DescribeVpcPeeringConnections' => new Result(['VpcPeeringConnections' => [[
            'VpcPeeringConnectionId' => 'pcx-1',
            'Status' => ['Code' => 'active'],
        ]]]),
        'ModifyVpcPeeringConnectionOptions' => new Result(),
    ], $captured);

    expect((new SyncVpcPeeringDnsStep())([]))->toBe(StepResult::SYNCED);

    $options = collect($captured)->firstWhere('name', 'ModifyVpcPeeringConnectionOptions');
    expect($options['args']['VpcPeeringConnectionId'])->toBe('pcx-1')
        ->and($options['args']['RequesterPeeringConnectionOptions'])->toBe(['AllowDnsResolutionFromRemoteVpc' => true])
        ->and($options['args']['AccepterPeeringConnectionOptions'])->toBe(['AllowDnsResolutionFromRemoteVpc' => true]);
});

it('reports a clean plan when DNS resolution is already on both ways', function (): void {
    declarePeering(['vpc-0abc123']);

    $captured = [];
    bindMockEc2Client([
        'DescribeVpcPeeringConnections' => new Result(['VpcPeeringConnections' => [[
            'VpcPeeringConnectionId' => 'pcx-1',
            'Status' => ['Code' => 'active'],
            'RequesterVpcInfo' => ['PeeringOptions' => ['AllowDnsResolutionFromRemoteVpc' => true]],
            'AccepterVpcInfo' => ['PeeringOptions' => ['AllowDnsResolutionFromRemoteVpc' => true]],
        ]]]),
    ], $captured);

    $step = new SyncVpcPeeringDnsStep();

    expect($step(['dry-run' => true]))->toBe(StepResult::SYNCED)
        ->and($step->changes())->toBeEmpty();
});

it('skips DNS enablement when nothing is declared', function (): void {
    declarePeering([]);

    expect((new SyncVpcPeeringDnsStep())(['dry-run' => true]))->toBe(StepResult::SKIPPED);
});

// ── Teardown — the reverse of the bring-up ───────────────────────────────────

/**
 * Mocks for tearing down the pcx-old bridge to vpc-retired: env CIDR
 * 10.1.0.0/16, outbound routes in both yolo tables, and a peer table carrying
 * the return route plus two routes YOLO must never touch.
 */
function bindPeeringTeardownMocks(array &$captured): void
{
    $liveConnection = new Result(['VpcPeeringConnections' => [[
        'VpcPeeringConnectionId' => 'pcx-old',
        'Status' => ['Code' => 'active'],
        'AccepterVpcInfo' => ['VpcId' => 'vpc-retired'],
    ]]]);

    $peerRouteTables = new Result(['RouteTables' => [
        subnetAssociatedPeerRouteTable('rtb-peer-a', [
            ['DestinationCidrBlock' => '10.1.0.0/16', 'VpcPeeringConnectionId' => 'pcx-old', 'State' => 'active'],
            ['DestinationCidrBlock' => '0.0.0.0/0', 'GatewayId' => 'igw-peer', 'State' => 'active'],
            ['DestinationCidrBlock' => '10.9.0.0/16', 'VpcPeeringConnectionId' => 'pcx-other', 'State' => 'active'],
        ]),
    ]]);

    bindMockEc2Client([
        'DescribeVpcPeeringConnections' => $liveConnection,
        'DescribeVpcs' => new Result(['Vpcs' => [['VpcId' => 'vpc-env', 'CidrBlock' => '10.1.0.0/16']]]),
        // Queue order: the foreign-route plan read (peer VPC), then delete()'s
        // yolo public + private tables, then its own peer VPC read.
        'DescribeRouteTables' => [
            $peerRouteTables,
            new Result(['RouteTables' => [['RouteTableId' => 'rtb-public', 'Routes' => [
                ['DestinationCidrBlock' => '172.31.0.0/16', 'VpcPeeringConnectionId' => 'pcx-old', 'State' => 'active'],
            ]]]]),
            new Result(['RouteTables' => [['RouteTableId' => 'rtb-private', 'Routes' => [
                ['DestinationCidrBlock' => '172.31.0.0/16', 'VpcPeeringConnectionId' => 'pcx-old', 'State' => 'active'],
            ]]]]),
            $peerRouteTables,
        ],
        'ModifyVpcPeeringConnectionOptions' => new Result(),
        'DeleteRoute' => new Result(),
        'DeleteVpcPeeringConnection' => new Result(),
    ], $captured);
}

it('plans the teardown of an undeclared peering with its foreign route reclaim named, writing nothing', function (): void {
    declarePeering([]);

    $captured = [];
    bindPeeringTeardownMocks($captured);

    $step = new SyncVpcPeeringStep();

    expect($step(['dry-run' => true]))->toBe(StepResult::WOULD_DELETE)
        ->and(collect($step->changes())->pluck('attribute'))->toContain('return route 10.1.0.0/16 (peer rtb-peer-a — not yolo-managed)')
        ->and(collect($captured)->pluck('name'))->not->toContain('ModifyVpcPeeringConnectionOptions', 'DeleteRoute', 'DeleteVpcPeeringConnection');
});

it('tears the bridge down in reverse — DNS off, yolo routes, only its own peer return route, then the connection', function (): void {
    declarePeering([]);

    $captured = [];
    bindPeeringTeardownMocks($captured);

    expect((new SyncVpcPeeringStep())([]))->toBe(StepResult::DELETED);

    $calls = collect($captured)->pluck('name');
    $deletes = collect($captured)->where('name', 'DeleteRoute')->values();

    // Only the routes this connection carries come out — the peer's default
    // route and the other connection's route are untouched.
    expect($deletes)->toHaveCount(3)
        ->and($deletes[0]['args'])->toMatchArray(['RouteTableId' => 'rtb-public', 'DestinationCidrBlock' => '172.31.0.0/16'])
        ->and($deletes[1]['args'])->toMatchArray(['RouteTableId' => 'rtb-private', 'DestinationCidrBlock' => '172.31.0.0/16'])
        ->and($deletes[2]['args'])->toMatchArray(['RouteTableId' => 'rtb-peer-a', 'DestinationCidrBlock' => '10.1.0.0/16']);

    // Reverse bring-up order: DNS resolution off before any route is
    // reclaimed, the connection dead last.
    $dnsOff = collect($captured)->firstWhere('name', 'ModifyVpcPeeringConnectionOptions');
    expect($dnsOff['args']['RequesterPeeringConnectionOptions'])->toBe(['AllowDnsResolutionFromRemoteVpc' => false])
        ->and($dnsOff['args']['AccepterPeeringConnectionOptions'])->toBe(['AllowDnsResolutionFromRemoteVpc' => false])
        ->and($calls->search('ModifyVpcPeeringConnectionOptions'))->toBeLessThan($calls->search('DeleteRoute'))
        ->and($calls->last())->toBe('DeleteVpcPeeringConnection');
});

it('destroy:environment reclaims the peer-side return route with the connection', function (): void {
    $captured = [];
    bindPeeringTeardownMocks($captured);

    $step = new TeardownVpcPeeringConnectionsStep();

    expect($step(['dry-run' => true]))->toBe(StepResult::WOULD_DELETE)
        ->and(collect($step->changes())->pluck('attribute'))->toContain('return route 10.1.0.0/16 (peer rtb-peer-a — not yolo-managed)')
        ->and(collect($captured)->pluck('name'))->not->toContain('DeleteRoute', 'DeleteVpcPeeringConnection');

    $captured = [];
    bindPeeringTeardownMocks($captured);

    expect((new TeardownVpcPeeringConnectionsStep())([]))->toBe(StepResult::DELETED)
        ->and(collect($captured)->where('name', 'DeleteRoute')->pluck('args.RouteTableId'))->toContain('rtb-peer-a')
        ->and(collect($captured)->pluck('name'))->toContain('DeleteVpcPeeringConnection');
});
