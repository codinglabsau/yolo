<?php

declare(strict_types=1);

use Aws\Result;
use Codinglabs\Yolo\Resources\Ec2\Vpc;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);
});

/**
 * @param  array<int, string>  $existing
 */
function bindExistingVpcs(array $existing, array &$captured): void
{
    bindMockEc2Client([
        'DescribeVpcs' => new Result(['Vpcs' => collect($existing)
            ->map(fn (string $cidr): array => ['CidrBlock' => $cidr])
            ->all()]),
        'CreateVpc' => new Result(),
    ], $captured);
}

it('claims 10.1.0.0/16 on a fresh account with no VPCs', function (): void {
    $captured = [];
    bindExistingVpcs([], $captured);

    expect((new Vpc())->availableCidrBlock())->toBe('10.1.0.0/16');
});

it('picks the lowest /16 that overlaps nothing already in the region', function (array $existing, string $expected): void {
    $captured = [];
    bindExistingVpcs($existing, $captured);

    expect((new Vpc())->availableCidrBlock())->toBe($expected);
})->with([
    '10.1 taken → 10.2' => [['10.1.0.0/16'], '10.2.0.0/16'],
    '10.1 + 10.2 taken → 10.3' => [['10.1.0.0/16', '10.2.0.0/16'], '10.3.0.0/16'],
    'a /24 inside 10.1 still blocks the whole /16' => [['10.1.5.0/24'], '10.2.0.0/16'],
    'reuses a freed lower gap' => [['10.2.0.0/16', '10.3.0.0/16'], '10.1.0.0/16'],
    'a /15 supernet covering 10.1 blocks it' => [['10.0.0.0/15'], '10.2.0.0/16'],
    'an unrelated default VPC is ignored' => [['172.31.0.0/16'], '10.1.0.0/16'],
]);

it('reads every association block, not just the primary CIDR', function (): void {
    $captured = [];
    bindMockEc2Client(['DescribeVpcs' => new Result(['Vpcs' => [[
        'CidrBlock' => '10.1.0.0/16',
        'CidrBlockAssociationSet' => [
            ['CidrBlock' => '10.1.0.0/16'],
            ['CidrBlock' => '10.2.0.0/16'],
        ],
    ]]])], $captured);

    expect((new Vpc())->availableCidrBlock())->toBe('10.3.0.0/16');
});

it('creates the VPC with the auto-selected CIDR and a vpc tag spec', function (): void {
    $captured = [];
    bindExistingVpcs(['10.1.0.0/16'], $captured);

    (new Vpc())->create();

    $create = collect($captured)->firstWhere('name', 'CreateVpc');
    expect($create['args']['CidrBlock'])->toBe('10.2.0.0/16')
        ->and($create['args']['TagSpecifications'][0]['ResourceType'])->toBe('vpc');
});

it('fails clearly when every 10.x /16 is in use', function (): void {
    $captured = [];
    bindExistingVpcs(['10.0.0.0/8'], $captured);

    expect(fn (): string => (new Vpc())->availableCidrBlock())
        ->toThrow(IntegrityCheckException::class);
});
