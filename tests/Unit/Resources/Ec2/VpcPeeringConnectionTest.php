<?php

declare(strict_types=1);

use Aws\Result;
use Codinglabs\Yolo\Resources\Ec2\VpcPeeringConnection;

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);
});

it('creates the connection from the env VPC, accepts it, and enables DNS resolution both ways', function (): void {
    $captured = [];
    bindMockEc2Client([
        'DescribeVpcs' => new Result(['Vpcs' => [['VpcId' => 'vpc-env']]]),
        'CreateVpcPeeringConnection' => new Result(),
        // The reconcile that follows the create: pending-acceptance, DNS off.
        'DescribeVpcPeeringConnections' => new Result(['VpcPeeringConnections' => [[
            'VpcPeeringConnectionId' => 'pcx-1',
            'Status' => ['Code' => 'pending-acceptance'],
        ]]]),
        'AcceptVpcPeeringConnection' => new Result(),
        'ModifyVpcPeeringConnectionOptions' => new Result(),
    ], $captured);

    (new VpcPeeringConnection('vpc-peer'))->create();

    $create = collect($captured)->firstWhere('name', 'CreateVpcPeeringConnection');
    expect($create['args']['VpcId'])->toBe('vpc-env')
        ->and($create['args']['PeerVpcId'])->toBe('vpc-peer');

    expect(collect($captured)->firstWhere('name', 'AcceptVpcPeeringConnection')['args']['VpcPeeringConnectionId'])->toBe('pcx-1');

    $options = collect($captured)->firstWhere('name', 'ModifyVpcPeeringConnectionOptions');
    expect($options['args']['RequesterPeeringConnectionOptions'])->toBe(['AllowDnsResolutionFromRemoteVpc' => true])
        ->and($options['args']['AccepterPeeringConnectionOptions'])->toBe(['AllowDnsResolutionFromRemoteVpc' => true]);
});

it('reports a clean config when active with DNS resolution already on', function (): void {
    $captured = [];
    bindMockEc2Client([
        'DescribeVpcPeeringConnections' => new Result(['VpcPeeringConnections' => [[
            'VpcPeeringConnectionId' => 'pcx-1',
            'Status' => ['Code' => 'active'],
            'RequesterVpcInfo' => ['PeeringOptions' => ['AllowDnsResolutionFromRemoteVpc' => true]],
            'AccepterVpcInfo' => ['PeeringOptions' => ['AllowDnsResolutionFromRemoteVpc' => true]],
        ]]]),
    ], $captured);

    expect((new VpcPeeringConnection('vpc-peer'))->synchroniseConfiguration(apply: false))->toBe([])
        ->and(collect($captured)->pluck('name'))->not->toContain('AcceptVpcPeeringConnection', 'ModifyVpcPeeringConnectionOptions');
});

it('only looks up live connections — a lingering deleted one reads as absent', function (): void {
    $captured = [];
    bindMockEc2Client([
        // The status-code filter is in the request, so AWS returns nothing.
        'DescribeVpcPeeringConnections' => new Result(['VpcPeeringConnections' => []]),
    ], $captured);

    expect((new VpcPeeringConnection('vpc-peer'))->exists())->toBeFalse();

    $describe = collect($captured)->firstWhere('name', 'DescribeVpcPeeringConnections');
    expect(collect($describe['args']['Filters'])->firstWhere('Name', 'status-code')['Values'])
        ->toContain('active', 'pending-acceptance');
});
